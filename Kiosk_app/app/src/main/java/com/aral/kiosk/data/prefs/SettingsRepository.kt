package com.aral.kiosk.data.prefs

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore(name = "kiosk_prefs")

class SettingsRepository(private val context: Context) {

    private val keyServerUrl   = stringPreferencesKey("server_url")
    private val keyMitarbeiter = stringPreferencesKey("mitarbeiter")
    private val keyStationId   = stringPreferencesKey("station_id")

    val serverUrl: Flow<String> = context.dataStore.data.map { it[keyServerUrl] ?: DEFAULT_SERVER_URL }
    val mitarbeiter: Flow<String> = context.dataStore.data.map { it[keyMitarbeiter] ?: "" }
    val stationId: Flow<String> = context.dataStore.data.map { it[keyStationId] ?: "" }

    suspend fun currentServerUrl(): String = serverUrl.first()
    suspend fun currentMitarbeiter(): String = mitarbeiter.first()
    suspend fun currentStationId(): String = stationId.first()

    suspend fun setServerUrl(url: String) {
        context.dataStore.edit { it[keyServerUrl] = url.trim() }
    }
    suspend fun setMitarbeiter(name: String) {
        context.dataStore.edit { it[keyMitarbeiter] = name.trim() }
    }
    suspend fun setStationId(id: String) {
        context.dataStore.edit { it[keyStationId] = id.trim() }
    }

    companion object {
        const val DEFAULT_SERVER_URL = "http://10.0.2.2/kiosk/"   // Android-Emulator → Host-PC
    }
}
