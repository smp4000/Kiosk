package com.aral.kiosk

import android.app.Application
import com.aral.kiosk.data.api.KioskApiClient
import com.aral.kiosk.data.prefs.SettingsRepository

class KioskApp : Application() {

    lateinit var settings: SettingsRepository
        private set

    lateinit var apiClient: KioskApiClient
        private set

    override fun onCreate() {
        super.onCreate()
        instance = this
        settings = SettingsRepository(applicationContext)
        apiClient = KioskApiClient(settings)
    }

    companion object {
        lateinit var instance: KioskApp
            private set
    }
}
