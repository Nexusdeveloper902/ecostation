plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Release signing comes from Gradle properties or env vars, never from the repo.
fun signingInput(prop: String, env: String): String? =
    (findProperty(prop) as String?)?.takeIf { it.isNotBlank() } ?: System.getenv(env)?.takeIf { it.isNotBlank() }

val sigStoreFile = signingInput("ecostation.signing.storeFile", "ECOSTATION_SIGNING_STORE_FILE")
val sigStorePassword = signingInput("ecostation.signing.storePassword", "ECOSTATION_SIGNING_STORE_PASSWORD")
val sigKeyAlias = signingInput("ecostation.signing.keyAlias", "ECOSTATION_SIGNING_KEY_ALIAS")
val sigKeyPassword = signingInput("ecostation.signing.keyPassword", "ECOSTATION_SIGNING_KEY_PASSWORD")
val signingComplete = listOf(sigStoreFile, sigStorePassword, sigKeyAlias, sigKeyPassword).all { it != null }
// The keystore must live outside the monorepo (rootDir is app/, its parent is the repo root).
val keyInsideRepo = sigStoreFile != null &&
    file(sigStoreFile).canonicalPath.startsWith(rootDir.parentFile.canonicalPath + File.separator)

android {
    namespace = "co.ecostation.app"
    compileSdk = 35 // must match an installed SDK platform (35 present)

    defaultConfig {
        applicationId = "co.ecostation.app"
        minSdk = 24 // HCE requires API 19+; 24 covers all maintained devices
        targetSdk = 34
        versionCode = 2
        versionName = "1.0.1"
        // Instrumented tests use the platform's own android.test runner
        // (useLibrary below): no AndroidX, not even for tests.
        testInstrumentationRunner = "android.test.InstrumentationTestRunner"
        // The AID MUST match the reader firmware and res/xml/apduservice.xml.
        // NO HCE SECRET HERE: each credential's key is generated on the phone
        // and kept in the Android Keystore. Never add a key to this file.
        buildConfigField("String", "ECO_AID_HEX", "\"F0010203040506\"")
        // BLANK ON PURPOSE: every installation derives its own credential id.
        buildConfigField("String", "ECO_CRED_ID", "\"\"")
    }

    buildFeatures {
        buildConfig = true
    }

    // Platform test libraries (android.test.*), shipped with the SDK.
    useLibrary("android.test.runner")
    useLibrary("android.test.base")
    useLibrary("android.test.mock")

    if (signingComplete && !keyInsideRepo) {
        signingConfigs {
            create("release") {
                this.storeFile = file(sigStoreFile!!)
                this.storePassword = sigStorePassword
                this.keyAlias = sigKeyAlias
                this.keyPassword = sigKeyPassword
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (signingComplete && !keyInsideRepo) signingConfig = signingConfigs.getByName("release")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

fun requireReleaseSigning() {
    if (!signingComplete || keyInsideRepo) {
        throw GradleException("Falta la llave de firma de release: ver app/README.md §Firma de release")
    }
}

// `preReleaseBuild` is a dependency of BOTH the APK graph (assembleRelease)
// and the bundle graph (bundleRelease). Guarding it closes the hole where
// packageReleaseBundle/signReleaseBundle produced an unsigned AAB.
tasks.matching { it.name == "preReleaseBuild" || it.name == "packageRelease" }.configureEach {
    doFirst { requireReleaseSigning() }
}

dependencies {
    testImplementation("junit:junit:4.13.2")
    // Android's org.json is a stub on the host JVM; the real one for tests.
    testImplementation("org.json:json:20240303")
}
