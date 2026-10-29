import java.io.FileInputStream
import java.util.Properties

// The Maps SDK key lives in local.properties, which is gitignored — a Maps
// key is billable, so it must never reach the repository. An absent key is
// deliberately not a build failure: the app still builds and runs, and only
// the map surface itself renders blank.
val mapsApiKey: String = Properties().apply {
    val file = rootProject.file("local.properties")
    if (file.exists()) {
        FileInputStream(file).use { load(it) }
    }
}.getProperty("MAPS_API_KEY") ?: ""

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.servicemarketplace.service_marketplace"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        applicationId = "com.servicemarketplace.service_marketplace"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName

        // Consumed by the <meta-data> entry in AndroidManifest.xml.
        manifestPlaceholders["MAPS_API_KEY"] = mapsApiKey
    }

    // Three store listings from one codebase. Each flavour gets its own
    // applicationId, so all three install side by side on the same device —
    // which matters in the field, where a salesman may also want the vendor
    // app to see what their vendors see.
    //
    // Run with:
    //   flutter run --flavor salesman -t lib/main_salesman.dart
    //
    // The --flavor name must match a productFlavor below, and the -t entry
    // point must match it too. Gradle does not check that pairing, so a
    // mismatched pair builds the wrong app under the right package name.
    flavorDimensions += "app"

    // appLinkScheme: the "Open the app" link on the password page
    // (<scheme>://login, AndroidManifest.xml). Must match
    // PasswordResetWebController::appLinkFor() on the server.
    productFlavors {
        create("salesman") {
            dimension = "app"
            applicationIdSuffix = ".salesman"
            resValue("string", "app_name", "IBE Salesman")
            manifestPlaceholders["appLinkScheme"] = "smsalesman"
        }
        create("vendor") {
            dimension = "app"
            applicationIdSuffix = ".vendor"
            resValue("string", "app_name", "IBE Vendor")
            manifestPlaceholders["appLinkScheme"] = "smvendor"
        }
        create("customer") {
            dimension = "app"
            // No suffix: the customer app is the primary listing.
            resValue("string", "app_name", "IBE Customer")
            manifestPlaceholders["appLinkScheme"] = "smcustomer"
        }
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

flutter {
    source = "../.."
}
