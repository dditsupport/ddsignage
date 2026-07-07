import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
}

// Load release-signing secrets from <repo>/keystore.properties.
// That file is gitignored — keeps the .jks password out of source control
// while still letting `gradlew assembleRelease` produce a signed APK on
// any dev machine that has a copy of the file (and the .jks at the
// referenced path).
//
// If the file is absent, the release build still runs but produces an
// unsigned APK (and Gradle prints a warning). Debug builds are unaffected.
val keystoreProps = Properties().apply {
    val f = rootProject.file("keystore.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}

android {
    namespace = "biz.aromen.signage"
    compileSdk {
        version = release(36) {
            minorApiLevel = 1
        }
    }

    signingConfigs {
        create("release") {
            val storeFilePath = keystoreProps.getProperty("storeFile")
            if (!storeFilePath.isNullOrBlank()) {
                storeFile = file(storeFilePath)
                storePassword = keystoreProps.getProperty("storePassword")
                keyAlias = keystoreProps.getProperty("keyAlias")
                keyPassword = keystoreProps.getProperty("keyPassword")
            }
        }
    }

    defaultConfig {
        applicationId = "biz.aromen.signage"
        minSdk = 23
        targetSdk = 36
        versionCode = 17
        versionName = "2.4.1"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildTypes {
        release {
            // Only attach the signing config if the keystore file resolved
            // (i.e. keystore.properties existed and pointed at a real .jks).
            // Otherwise the APK comes out unsigned — still useful for a
            // local lint pass even without the keys on this machine.
            val rs = signingConfigs.getByName("release")
            if (rs.storeFile != null) {
                signingConfig = rs
            }
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
    buildFeatures {
        buildConfig = true
    }
}

dependencies {
    implementation(libs.androidx.activity.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.androidx.constraintlayout)
    implementation(libs.androidx.core.ktx)
    implementation(libs.material)
    testImplementation(libs.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.junit)
}

// Rename APK outputs to include versionName + versionCode + build type,
// so the file in app/build/outputs/apk/<buildType>/ self-describes:
//   app-debug.apk    ->  signage-v2.4-15-debug.apk
//   app-release.apk  ->  signage-v2.4-15-release.apk
// (Format: signage-v<versionName>-<versionCode>-<buildType>.apk.) The
// versionCode is the authoritative monotonically-increasing ID — two
// builds at versionName "2.4" with different codes are visibly different
// here, which matters when iterating during a release. Uses Provider
// chaining (.flatMap + .map) so both values stay lazy and configuration
// cache stays happy.
androidComponents {
    onVariants { variant ->
        variant.outputs.forEach { output ->
            output.outputFileName.set(
                output.versionName.flatMap { name ->
                    output.versionCode.map { code ->
                        "signage-v${name ?: "unknown"}-${code ?: 0}-${variant.name}.apk"
                    }
                }
            )
        }
    }
}