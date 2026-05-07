package com.aral.kiosk.data.api

import com.aral.kiosk.data.prefs.SettingsRepository
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import java.util.concurrent.TimeUnit

class KioskApiClient(private val settings: SettingsRepository) {

    private val json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
        coerceInputValues = true
    }

    @Volatile private var current: KioskApi? = null
    @Volatile private var currentBase: String = ""

    fun api(): KioskApi {
        val base = runBlocking { settings.currentServerUrl() }.let(::ensureTrailingSlash)
        val cached = current
        if (cached != null && currentBase == base) return cached

        val logging = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BASIC
        }
        val ok = OkHttpClient.Builder()
            .connectTimeout(8, TimeUnit.SECONDS)
            .readTimeout(15, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)
            .addInterceptor(logging)
            .build()

        val retrofit = Retrofit.Builder()
            .baseUrl(base)
            .client(ok)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()

        val instance = retrofit.create(KioskApi::class.java)
        current = instance
        currentBase = base
        return instance
    }

    private fun ensureTrailingSlash(s: String): String =
        if (s.isBlank()) SettingsRepository.DEFAULT_SERVER_URL
        else if (s.endsWith("/")) s else "$s/"
}
