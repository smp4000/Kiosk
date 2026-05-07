package com.aral.kiosk.ui.screens.lieferung

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.aral.kiosk.KioskApp
import com.aral.kiosk.data.api.*
import com.aral.kiosk.ui.screens.ErfasstePosition
import com.aral.kiosk.ui.screens.defaultLieferKw
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class PendingScan(
    val ean: String,
    val matches: List<Article>,
    val eanInfo: EanInfo?,
)

data class LieferungState(
    val lieferscheinNr: String = "",
    val lieferscheinDatum: String = today(),
    val positionen: List<ErfasstePosition> = emptyList(),
    val pending: PendingScan? = null,
    val message: String? = null,
    val isError: Boolean = false,
    val saving: Boolean = false,
    val savedId: Int? = null,
)

private fun today(): String {
    val c = java.util.Calendar.getInstance()
    return "%04d-%02d-%02d".format(
        c.get(java.util.Calendar.YEAR),
        c.get(java.util.Calendar.MONTH) + 1,
        c.get(java.util.Calendar.DAY_OF_MONTH),
    )
}

class LieferungViewModel : ViewModel() {

    private val app = KioskApp.instance
    private val _state = MutableStateFlow(LieferungState())
    val state: StateFlow<LieferungState> = _state.asStateFlow()

    fun setLieferscheinNr(s: String) = _state.update { it.copy(lieferscheinNr = s) }
    fun setLieferscheinDatum(s: String) = _state.update { it.copy(lieferscheinDatum = s) }

    fun onScan(rawCode: String) {
        val code = rawCode.filter { it.isDigit() }
        when {
            code.length == 13 -> lookupByEan(code)
            code.length in 4..10 -> lookupByObjekt(code)
            else -> setMsg("Ungültiger Scan: $rawCode", isError = true)
        }
    }

    private fun lookupByEan(ean: String) {
        viewModelScope.launch {
            try {
                val r = app.apiClient.api().lookupByEan(ean)
                when {
                    r.count == 0 -> _state.update { it.copy(pending = PendingScan(ean, emptyList(), r.eanInfo), message = null) }
                    r.count == 1 -> addPosition(r.articles.first(), scannedEan = ean)
                    else -> _state.update { it.copy(pending = PendingScan(ean, r.articles, r.eanInfo), message = null) }
                }
            } catch (e: Throwable) {
                setMsg("Fehler beim Lookup: ${e.message}", isError = true)
            }
        }
    }

    private fun lookupByObjekt(objekt: String) {
        viewModelScope.launch {
            try {
                val r = app.apiClient.api().byObjekt(objekt)
                if (r.ok && r.article != null) {
                    addPosition(r.article, scannedEan = null)
                } else {
                    setMsg("Kein Artikel mit objekt=$objekt", isError = true)
                }
            } catch (e: Throwable) {
                setMsg("Fehler: ${e.message}", isError = true)
            }
        }
    }

    fun choosePending(article: Article) {
        val ean = _state.value.pending?.ean
        addPosition(article, scannedEan = ean)
        _state.update { it.copy(pending = null) }
    }

    fun dismissPending() = _state.update { it.copy(pending = null) }

    fun createPendingArticle(bezeichnung: String) {
        val p = _state.value.pending ?: return
        viewModelScope.launch {
            try {
                val r = app.apiClient.api().upsertPending(
                    UpsertPendingRequest(ean = p.ean, bezeichnung = bezeichnung.ifBlank { "Zeitschrift" })
                )
                if (r.ok && r.articleId > 0) {
                    val r2 = app.apiClient.api().lookupByEan(p.ean)
                    val art = r2.articles.firstOrNull { it.id == r.articleId } ?: r2.articles.firstOrNull()
                    if (art != null) {
                        addPosition(art, scannedEan = p.ean)
                        _state.update { it.copy(pending = null) }
                    } else {
                        setMsg("Pending angelegt, aber Artikel nicht gefunden.", isError = true)
                    }
                } else {
                    setMsg("Anlegen fehlgeschlagen: ${r.error ?: "?"}", isError = true)
                }
            } catch (e: Throwable) {
                setMsg("Fehler: ${e.message}", isError = true)
            }
        }
    }

    private fun addPosition(article: Article, scannedEan: String?) {
        val ausgabe = article.ausgaben.firstOrNull() ?: defaultLieferKw()
        val existing = _state.value.positionen.indexOfFirst {
            it.article.id == article.id && it.ausgabe == ausgabe
        }
        if (existing >= 0) {
            val updated = _state.value.positionen.toMutableList()
            val old = updated[existing]
            updated[existing] = old.copy(menge = old.menge + 1)
            _state.update { it.copy(positionen = updated, message = "+1 ${article.bezeichnung}", isError = false) }
        } else {
            _state.update {
                it.copy(
                    positionen = it.positionen + ErfasstePosition(article, ausgabe, 1, scannedEan),
                    message = "Hinzugefügt: ${article.bezeichnung}",
                    isError = false,
                )
            }
        }
    }

    fun changeMenge(index: Int, newMenge: Int) {
        val list = _state.value.positionen.toMutableList()
        if (index !in list.indices) return
        if (newMenge <= 0) list.removeAt(index)
        else list[index] = list[index].copy(menge = newMenge)
        _state.update { it.copy(positionen = list) }
    }

    fun changeAusgabe(index: Int, ausgabe: String) {
        val list = _state.value.positionen.toMutableList()
        if (index !in list.indices) return
        list[index] = list[index].copy(ausgabe = ausgabe)
        _state.update { it.copy(positionen = list) }
    }

    fun save() {
        val s = _state.value
        if (s.positionen.isEmpty()) {
            setMsg("Keine Positionen erfasst.", isError = true); return
        }
        viewModelScope.launch {
            _state.update { it.copy(saving = true, message = null) }
            try {
                val mitarbeiter = app.settings.currentMitarbeiter().ifBlank { null }
                val stationId = app.settings.currentStationId().toIntOrNull()
                val r = app.apiClient.api().saveDelivery(
                    SaveDeliveryRequest(
                        lieferscheinNr = s.lieferscheinNr.ifBlank { null },
                        lieferscheinDatum = s.lieferscheinDatum.ifBlank { null },
                        mitarbeiter = mitarbeiter,
                        stationId = stationId,
                        items = s.positionen.map { it.toSaveItem() },
                    ),
                    mitarbeiter = mitarbeiter,
                )
                if (r.ok) {
                    _state.update {
                        LieferungState(
                            message = "Lieferung #${r.deliveryId} gespeichert (${r.itemsSaved} Positionen).",
                            savedId = r.deliveryId,
                        )
                    }
                } else {
                    setMsg("Fehler: ${r.error ?: "unbekannt"}", isError = true)
                }
            } catch (e: Throwable) {
                setMsg("Speichern fehlgeschlagen: ${e.message}", isError = true)
            } finally {
                _state.update { it.copy(saving = false) }
            }
        }
    }

    private fun setMsg(msg: String, isError: Boolean) {
        _state.update { it.copy(message = msg, isError = isError) }
    }
}
