package com.servicemarketplace.service_marketplace

import android.content.Context
import android.content.Intent
import android.os.Build
import android.provider.Settings

/**
 * The one place the exact call timing lives (customer app, Android only).
 *
 * Kept in the app's own SharedPreferences rather than in memory so that it
 * survives the app being closed while the customer is on the call: the
 * phone-state receiver writes the result even with no app process running,
 * and Flutter picks it up (CallTracker.flush) the next time it is open.
 *
 * Flow: Flutter calls [begin] with the lead id just before opening the
 * dialler → the dial goes off-hook ([onOffhook]) → the line goes idle
 * ([onIdle]) → the length is held as "finished" until Flutter has sent it.
 * Off-hook to idle includes ringing; Android does not tell an ordinary app
 * when the other side picked up.
 */
object CallTrackerStore {
    private const val PREFS = "call_tracker"
    private const val PENDING_LEAD = "pending_lead"
    private const val PENDING_AT = "pending_at"
    private const val OFFHOOK_AT = "offhook_at"
    private const val FINISHED_LEAD = "finished_lead"
    private const val FINISHED_SECONDS = "finished_seconds"

    /** Same cap as Lead::MAX_CALL_DURATION_SECONDS on the server. */
    private const val MAX_SECONDS = 3600L

    /** A pending call older than this is abandoned — matches the server's report window. */
    private const val PENDING_WINDOW_MS = 3L * 60 * 60 * 1000

    /** The dialler was opened but the customer never pressed call within this. */
    const val NEVER_DIALLED_MS = 2L * 60 * 1000

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun begin(context: Context, leadId: Int) {
        prefs(context).edit()
            .putInt(PENDING_LEAD, leadId)
            .putLong(PENDING_AT, System.currentTimeMillis())
            .putLong(OFFHOOK_AT, 0L)
            .commit()
    }

    fun cancel(context: Context) {
        prefs(context).edit().remove(PENDING_LEAD).remove(PENDING_AT).remove(OFFHOOK_AT).commit()
        stopBubble(context)
    }

    fun hasPending(context: Context): Boolean {
        val p = prefs(context)
        if (!p.contains(PENDING_LEAD)) return false

        if (System.currentTimeMillis() - p.getLong(PENDING_AT, 0L) > PENDING_WINDOW_MS) {
            cancel(context)
            return false
        }

        return true
    }

    fun pendingAt(context: Context): Long = prefs(context).getLong(PENDING_AT, 0L)

    fun offhookAt(context: Context): Long = prefs(context).getLong(OFFHOOK_AT, 0L)

    fun onOffhook(context: Context) {
        if (hasPending(context) && offhookAt(context) == 0L) {
            prefs(context).edit().putLong(OFFHOOK_AT, System.currentTimeMillis()).commit()
        }
    }

    /** True when this idle ended a call we were timing. */
    fun onIdle(context: Context): Boolean {
        if (!hasPending(context)) return false

        val offhook = offhookAt(context)
        // Idle without ever going off-hook: nothing was dialled yet.
        if (offhook == 0L) return false

        val p = prefs(context)
        val seconds = ((System.currentTimeMillis() - offhook) / 1000).coerceIn(0L, MAX_SECONDS)

        p.edit()
            .putInt(FINISHED_LEAD, p.getInt(PENDING_LEAD, 0))
            .putInt(FINISHED_SECONDS, seconds.toInt())
            .remove(PENDING_LEAD)
            .remove(PENDING_AT)
            .remove(OFFHOOK_AT)
            .commit()

        return true
    }

    /** Back in the app without having dialled: drop the timing and the bubble. */
    fun dropIfNeverDialled(context: Context) {
        if (hasPending(context) &&
            offhookAt(context) == 0L &&
            System.currentTimeMillis() - pendingAt(context) > 1500L
        ) {
            cancel(context)
        }
    }

    /** {pending, leadId, seconds} for Flutter; leadId/seconds null until a call has finished. */
    fun poll(context: Context): Map<String, Any?> {
        val p = prefs(context)
        val finished = p.contains(FINISHED_LEAD)

        return mapOf(
            "pending" to hasPending(context),
            "leadId" to if (finished) p.getInt(FINISHED_LEAD, 0) else null,
            "seconds" to if (finished) p.getInt(FINISHED_SECONDS, 0) else null,
        )
    }

    fun clearFinished(context: Context) {
        prefs(context).edit().remove(FINISHED_LEAD).remove(FINISHED_SECONDS).commit()
    }

    fun canDrawOverlays(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.M || Settings.canDrawOverlays(context)

    fun startBubble(context: Context): Boolean {
        if (!canDrawOverlays(context)) return false

        return try {
            context.startService(Intent(context, CallBubbleService::class.java)) != null
        } catch (e: Exception) {
            false
        }
    }

    fun stopBubble(context: Context) {
        try {
            context.stopService(Intent(context, CallBubbleService::class.java))
        } catch (_: Exception) {
        }
    }

    /**
     * Brings the existing app task forward, the way tapping the launcher
     * icon does. Starting an activity from the background is only allowed
     * here because the app holds "Display over other apps".
     */
    fun bringAppToFront(context: Context) {
        val intent = context.packageManager.getLaunchIntentForPackage(context.packageName) ?: return

        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED)

        try {
            context.startActivity(intent)
        } catch (_: Exception) {
        }
    }
}
