package com.servicemarketplace.service_marketplace

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private var phonePermissionResult: MethodChannel.Result? = null

    /**
     * lib/utils/call_tracker.dart talks to the exact call timing through
     * this channel. The receiver and bubble service behind it are only
     * declared in the customer flavour's manifest.
     */
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CALL_TRACKER_CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "status" -> result.success(
                        mapOf(
                            "phone" to hasPhonePermission(),
                            "overlay" to CallTrackerStore.canDrawOverlays(this),
                        )
                    )

                    "requestPhonePermission" -> requestPhonePermission(result)

                    "openOverlaySettings" -> {
                        openOverlaySettings()
                        result.success(null)
                    }

                    "start" -> {
                        val leadId = call.argument<Int>("leadId")

                        if (leadId == null) {
                            result.error("BAD_ARGS", "leadId is required", null)
                        } else {
                            CallTrackerStore.begin(this, leadId)
                            result.success(CallTrackerStore.startBubble(this))
                        }
                    }

                    "appResumed" -> {
                        CallTrackerStore.dropIfNeverDialled(this)
                        result.success(null)
                    }

                    "poll" -> result.success(CallTrackerStore.poll(this))

                    "clearFinished" -> {
                        CallTrackerStore.clearFinished(this)
                        result.success(null)
                    }

                    else -> result.notImplemented()
                }
            }
    }

    private fun hasPhonePermission(): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.M ||
            checkSelfPermission(Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED

    private fun requestPhonePermission(result: MethodChannel.Result) {
        if (hasPhonePermission()) {
            result.success(true)
            return
        }

        // A second request while one is open would orphan the first result.
        phonePermissionResult?.success(false)
        phonePermissionResult = result
        requestPermissions(arrayOf(Manifest.permission.READ_PHONE_STATE), PHONE_PERMISSION_REQUEST)
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray,
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)

        if (requestCode == PHONE_PERMISSION_REQUEST) {
            phonePermissionResult?.success(
                grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED
            )
            phonePermissionResult = null
        }
    }

    private fun openOverlaySettings() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return

        try {
            startActivity(
                Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:$packageName"))
            )
        } catch (_: Exception) {
        }
    }

    companion object {
        private const val CALL_TRACKER_CHANNEL = "service_marketplace/call_tracker"
        private const val PHONE_PERMISSION_REQUEST = 4711
    }
}
