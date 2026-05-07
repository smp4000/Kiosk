package com.aral.kiosk.ui.screens.settings

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.aral.kiosk.KioskApp
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class SettingsState(
    val loaded: Boolean = false,
    val serverUrl: String = "",
    val mitarbeiter: String = "",
    val stationId: String = "",
    val message: String? = null,
    val isError: Boolean = false,
)

class SettingsViewModel : ViewModel() {

    private val app = KioskApp.instance
    private val _state = MutableStateFlow(SettingsState())
    val state: StateFlow<SettingsState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            _state.value = SettingsState(
                loaded = true,
                serverUrl = app.settings.currentServerUrl(),
                mitarbeiter = app.settings.currentMitarbeiter(),
                stationId = app.settings.currentStationId(),
            )
        }
    }

    fun save(url: String, mitarbeiter: String, stationId: String) {
        viewModelScope.launch {
            app.settings.setServerUrl(url)
            app.settings.setMitarbeiter(mitarbeiter)
            app.settings.setStationId(stationId)
            _state.update { it.copy(message = "Gespeichert.", isError = false) }
        }
    }

    fun testConnection(url: String) {
        viewModelScope.launch {
            app.settings.setServerUrl(url)
            try {
                val r = app.apiClient.api().ping()
                _state.update {
                    if (r.ok)
                        it.copy(message = "OK – ${r.articles ?: 0} Artikel im Backend.", isError = false)
                    else
                        it.copy(message = "Server-Antwort nicht OK: ${r.error ?: "unbekannt"}", isError = true)
                }
            } catch (e: Throwable) {
                _state.update { it.copy(message = "Verbindung fehlgeschlagen: ${e.message}", isError = true) }
            }
        }
    }
}
