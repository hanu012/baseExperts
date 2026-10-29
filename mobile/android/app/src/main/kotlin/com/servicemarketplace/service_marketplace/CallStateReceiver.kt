package com.servicemarketplace.service_marketplace

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.telephony.TelephonyManager

/**
 * Hears the phone go off-hook and back to idle (needs READ_PHONE_STATE).
 * Declared in the customer manifest, so it runs even when the app itself
 * has been closed during the call — that is what lets the length survive
 * a customer who never comes back to the app.
 *
 * Some phones send each state twice (once per SIM); CallTrackerStore
 * ignores the repeat because the first idle already cleared the pending call.
 */
class CallStateReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != TelephonyManager.ACTION_PHONE_STATE_CHANGED) return

        when (intent.getStringExtra(TelephonyManager.EXTRA_STATE)) {
            TelephonyManager.EXTRA_STATE_OFFHOOK -> CallTrackerStore.onOffhook(context)

            TelephonyManager.EXTRA_STATE_IDLE -> {
                if (CallTrackerStore.onIdle(context)) {
                    CallTrackerStore.stopBubble(context)

                    // The moment the call is cut, the customer is back in the app.
                    if (CallTrackerStore.canDrawOverlays(context)) {
                        CallTrackerStore.bringAppToFront(context)
                    }
                }
            }
        }
    }
}
