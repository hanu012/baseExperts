package com.servicemarketplace.service_marketplace

import android.annotation.SuppressLint
import android.app.Service
import android.content.Intent
import android.graphics.Color
import android.graphics.PixelFormat
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.view.Gravity
import android.view.MotionEvent
import android.view.View
import android.view.ViewConfiguration
import android.view.WindowManager
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import kotlin.math.abs

/**
 * The floating app bubble shown over the dialler while a vendor call is
 * being timed (needs "Display over other apps"). The app icon with a live
 * call timer under it; tap to come back to the app, drag to move it.
 *
 * It only shows the timing — the call end itself is caught by
 * [CallStateReceiver], so the length is still recorded if Android stops
 * this service.
 */
class CallBubbleService : Service() {
    private var windowManager: WindowManager? = null
    private var bubble: View? = null
    private var timer: TextView? = null
    private val handler = Handler(Looper.getMainLooper())

    private val tick = object : Runnable {
        override fun run() {
            refresh()
            handler.postDelayed(this, 1000L)
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (bubble == null) showBubble()
        return START_NOT_STICKY
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    @SuppressLint("ClickableViewAccessibility")
    private fun showBubble() {
        if (!CallTrackerStore.canDrawOverlays(this)) {
            stopSelf()
            return
        }

        val teal = Color.parseColor("#0F766E")

        val icon = ImageView(this).apply {
            setImageDrawable(packageManager.getApplicationIcon(packageName))
        }

        val iconHolder = FrameLayout(this).apply {
            background = GradientDrawable().apply {
                shape = GradientDrawable.OVAL
                setColor(Color.WHITE)
                setStroke(dp(2), teal)
            }
            elevation = dp(6).toFloat()
            setPadding(dp(6), dp(6), dp(6), dp(6))
            addView(icon, FrameLayout.LayoutParams(dp(44), dp(44)))
        }

        val label = TextView(this).apply {
            setTextColor(Color.WHITE)
            textSize = 12f
            typeface = Typeface.DEFAULT_BOLD
            background = GradientDrawable().apply {
                cornerRadius = dp(10).toFloat()
                setColor(teal)
            }
            setPadding(dp(8), dp(2), dp(8), dp(2))
            visibility = View.GONE
        }

        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_HORIZONTAL
            setPadding(dp(4), dp(4), dp(4), dp(4))
            addView(iconHolder)
            addView(label, LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT,
            ).apply { topMargin = dp(4) })
        }

        @Suppress("DEPRECATION")
        val type = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
        } else {
            WindowManager.LayoutParams.TYPE_PHONE
        }

        val metrics = resources.displayMetrics
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            type,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
            PixelFormat.TRANSLUCENT,
        ).apply {
            gravity = Gravity.TOP or Gravity.START
            x = metrics.widthPixels - dp(76)
            y = metrics.heightPixels / 3
        }

        // Drag to move; a touch that barely moved is a tap → back to the app.
        val slop = ViewConfiguration.get(this).scaledTouchSlop
        var downX = 0f
        var downY = 0f
        var startX = 0
        var startY = 0
        var dragged = false

        root.setOnTouchListener { view, event ->
            when (event.action) {
                MotionEvent.ACTION_DOWN -> {
                    downX = event.rawX
                    downY = event.rawY
                    startX = params.x
                    startY = params.y
                    dragged = false
                }

                MotionEvent.ACTION_MOVE -> {
                    val dx = event.rawX - downX
                    val dy = event.rawY - downY

                    if (dragged || abs(dx) > slop || abs(dy) > slop) {
                        dragged = true
                        params.x = startX + dx.toInt()
                        params.y = startY + dy.toInt()
                        windowManager?.updateViewLayout(view, params)
                    }
                }

                MotionEvent.ACTION_UP -> {
                    if (!dragged) {
                        view.performClick()
                        CallTrackerStore.bringAppToFront(this)
                    }
                }
            }
            true
        }

        val wm = getSystemService(WINDOW_SERVICE) as WindowManager

        try {
            wm.addView(root, params)
        } catch (e: Exception) {
            stopSelf()
            return
        }

        windowManager = wm
        bubble = root
        timer = label
        handler.post(tick)
    }

    /** Live m:ss once the call is placed; gives up if it never is. */
    private fun refresh() {
        if (!CallTrackerStore.hasPending(this)) {
            stopSelf()
            return
        }

        val offhook = CallTrackerStore.offhookAt(this)
        val now = System.currentTimeMillis()

        if (offhook == 0L) {
            if (now - CallTrackerStore.pendingAt(this) > CallTrackerStore.NEVER_DIALLED_MS) {
                CallTrackerStore.cancel(this)
            }
            return
        }

        val seconds = (now - offhook) / 1000
        timer?.apply {
            visibility = View.VISIBLE
            text = String.format("%d:%02d", seconds / 60, seconds % 60)
        }
    }

    override fun onDestroy() {
        handler.removeCallbacks(tick)
        bubble?.let {
            try {
                windowManager?.removeView(it)
            } catch (_: Exception) {
            }
        }
        bubble = null
        super.onDestroy()
    }
}
