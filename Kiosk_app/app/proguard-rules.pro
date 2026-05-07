# Keep model classes for kotlinx.serialization
-keepclassmembers,allowobfuscation class * {
    @kotlinx.serialization.SerialName <fields>;
}
-keep,includedescriptorclasses class com.aral.kiosk.**$$serializer { *; }
-keepclassmembers class com.aral.kiosk.** {
    *** Companion;
}
-keepclasseswithmembers class com.aral.kiosk.** {
    kotlinx.serialization.KSerializer serializer(...);
}
