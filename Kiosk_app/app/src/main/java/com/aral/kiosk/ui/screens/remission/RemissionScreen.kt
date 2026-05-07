package com.aral.kiosk.ui.screens.remission

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import com.aral.kiosk.KioskApp
import com.aral.kiosk.data.api.*
import com.aral.kiosk.ui.components.*
import com.aral.kiosk.ui.screens.ErfasstePosition
import com.aral.kiosk.ui.screens.defaultRemiKw
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class RemiState(
    val paket: String = "",
    val paketDatum: String = today(),
    val positionen: List<ErfasstePosition> = emptyList(),
    val pendingMatches: List<Article>? = null,
    val pendingEan: String? = null,
    val pendingEanInfo: EanInfo? = null,
    val message: String? = null,
    val isError: Boolean = false,
    val saving: Boolean = false,
)

private fun today(): String {
    val c = java.util.Calendar.getInstance()
    return "%04d-%02d-%02d".format(c.get(java.util.Calendar.YEAR), c.get(java.util.Calendar.MONTH) + 1, c.get(java.util.Calendar.DAY_OF_MONTH))
}

class RemissionViewModel : ViewModel() {
    private val app = KioskApp.instance
    private val _state = MutableStateFlow(RemiState())
    val state: StateFlow<RemiState> = _state.asStateFlow()

    fun setPaket(s: String) = _state.update { it.copy(paket = s.filter { c -> c.isDigit() }) }
    fun setPaketDatum(s: String) = _state.update { it.copy(paketDatum = s) }

    fun onScan(rawCode: String) {
        val code = rawCode.filter { it.isDigit() }
        viewModelScope.launch {
            try {
                if (code.length == 13) {
                    val r = app.apiClient.api().lookupByEan(code)
                    when (r.count) {
                        0 -> _state.update { it.copy(pendingEan = code, pendingMatches = emptyList(), pendingEanInfo = r.eanInfo) }
                        1 -> addPosition(r.articles.first(), code)
                        else -> _state.update { it.copy(pendingEan = code, pendingMatches = r.articles, pendingEanInfo = r.eanInfo) }
                    }
                } else if (code.length in 4..10) {
                    val r = app.apiClient.api().byObjekt(code)
                    if (r.ok && r.article != null) addPosition(r.article, null)
                    else setMsg("objekt=$code nicht gefunden", true)
                } else setMsg("Ungültig: $rawCode", true)
            } catch (e: Throwable) { setMsg("Fehler: ${e.message}", true) }
        }
    }

    fun choose(article: Article) {
        val ean = _state.value.pendingEan
        addPosition(article, ean)
        _state.update { it.copy(pendingEan = null, pendingMatches = null) }
    }
    fun dismissPending() = _state.update { it.copy(pendingEan = null, pendingMatches = null) }

    fun createPending(bezeichnung: String) {
        val ean = _state.value.pendingEan ?: return
        viewModelScope.launch {
            try {
                val r = app.apiClient.api().upsertPending(UpsertPendingRequest(ean = ean, bezeichnung = bezeichnung))
                if (r.ok) {
                    val r2 = app.apiClient.api().lookupByEan(ean)
                    val art = r2.articles.firstOrNull { it.id == r.articleId } ?: r2.articles.firstOrNull()
                    if (art != null) {
                        addPosition(art, ean)
                        _state.update { it.copy(pendingEan = null, pendingMatches = null) }
                    }
                }
            } catch (e: Throwable) { setMsg("Fehler: ${e.message}", true) }
        }
    }

    private fun addPosition(article: Article, scannedEan: String?) {
        // Default-Ausgabe: bevorzugt eine Vorwoche (kleinste KW < aktuelle), sonst defaultRemiKw
        val pref = article.ausgaben.minByOrNull { ag -> ag.toIntOrNull() ?: Int.MAX_VALUE }
        val ausgabe = pref ?: defaultRemiKw()
        val idx = _state.value.positionen.indexOfFirst { it.article.id == article.id && it.ausgabe == ausgabe }
        val list = _state.value.positionen.toMutableList()
        if (idx >= 0) {
            val o = list[idx]; list[idx] = o.copy(menge = o.menge + 1)
        } else {
            list += ErfasstePosition(article, ausgabe, 1, scannedEan)
        }
        _state.update { it.copy(positionen = list, message = "+1 ${article.bezeichnung}", isError = false) }
    }

    fun changeMenge(i: Int, v: Int) {
        val list = _state.value.positionen.toMutableList()
        if (i !in list.indices) return
        if (v <= 0) list.removeAt(i) else list[i] = list[i].copy(menge = v)
        _state.update { it.copy(positionen = list) }
    }
    fun changeAusgabe(i: Int, ag: String) {
        val list = _state.value.positionen.toMutableList()
        if (i !in list.indices) return
        list[i] = list[i].copy(ausgabe = ag)
        _state.update { it.copy(positionen = list) }
    }

    fun save() {
        val s = _state.value
        if (s.positionen.isEmpty()) { setMsg("Keine Positionen.", true); return }
        viewModelScope.launch {
            _state.update { it.copy(saving = true) }
            try {
                val emp = app.settings.currentMitarbeiter().ifBlank { null }
                val st = app.settings.currentStationId().toIntOrNull()
                val r = app.apiClient.api().saveRemission(
                    SaveRemissionRequest(
                        paket = s.paket.ifBlank { null }, paketDatum = s.paketDatum.ifBlank { null },
                        mitarbeiter = emp, stationId = st,
                        items = s.positionen.map { it.toSaveItem() },
                    ), mitarbeiter = emp,
                )
                if (r.ok) _state.value = RemiState(message = "Paket #${r.remiPackageId} gespeichert (${r.itemsSaved}).")
                else setMsg("Fehler: ${r.error ?: "?"}", true)
            } catch (e: Throwable) { setMsg("Speichern fehlgeschlagen: ${e.message}", true) }
            finally { _state.update { it.copy(saving = false) } }
        }
    }

    private fun setMsg(m: String, e: Boolean) = _state.update { it.copy(message = m, isError = e) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RemissionScreen(nav: NavController, vm: RemissionViewModel = viewModel()) {
    val s by vm.state.collectAsState()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Remission") },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "zurück")
                    }
                },
            )
        },
        bottomBar = {
            BottomAppBar {
                Spacer(Modifier.weight(1f))
                Text("${s.positionen.sumOf { it.menge }} Stück", modifier = Modifier.padding(end = 16.dp))
                Button(onClick = { vm.save() }, enabled = !s.saving && s.positionen.isNotEmpty(), modifier = Modifier.padding(end = 12.dp)) {
                    Text(if (s.saving) "Speichere…" else "Speichern")
                }
            }
        }
    ) { p ->
        Column(modifier = Modifier.padding(p).padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(value = s.paket, onValueChange = vm::setPaket, label = { Text("Paket-Nr") }, singleLine = true, modifier = Modifier.weight(1f))
                OutlinedTextField(value = s.paketDatum, onValueChange = vm::setPaketDatum, label = { Text("Datum") }, singleLine = true, modifier = Modifier.weight(1f))
            }
            ScanField(onScan = vm::onScan, placeholder = "EAN scannen oder Objekt-Nr eingeben")
            s.message?.let { StatusBox(it, color = if (s.isError) MaterialTheme.colorScheme.error else Color(0xFF15803D)) }
            HorizontalDivider()

            if (s.positionen.isEmpty()) Text("Noch keine Positionen.", color = MaterialTheme.colorScheme.outline)
            else LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.weight(1f)) {
                itemsIndexed(s.positionen) { idx, pos ->
                    ArticleCard(article = pos.article) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            AusgabePicker(
                                ausgaben = (pos.article.ausgaben + pos.ausgabe).filterNotNull().distinct(),
                                selected = pos.ausgabe, onSelect = { vm.changeAusgabe(idx, it) },
                            )
                            Spacer(Modifier.weight(1f))
                            MengenStepper(value = pos.menge, onChange = { vm.changeMenge(idx, it) })
                            IconButton(onClick = { vm.changeMenge(idx, 0) }) {
                                Icon(Icons.Default.Delete, contentDescription = "entfernen")
                            }
                        }
                    }
                }
            }
        }

        if (s.pendingMatches != null && s.pendingMatches!!.size > 1) {
            AlertDialog(
                onDismissRequest = vm::dismissPending,
                title = { Text("Wochentag wählen") },
                text = {
                    LazyColumn(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        items(s.pendingMatches!!) { a ->
                            Card(onClick = { vm.choose(a) }) {
                                Column(modifier = Modifier.padding(12.dp)) {
                                    Text("${a.bezeichnung}  (${weekdayLabel(a.weekday)})", style = MaterialTheme.typography.titleSmall)
                                    Text("Obj ${a.objekt} • VKP %.2f €".format(a.vkpBrutto), color = MaterialTheme.colorScheme.outline)
                                }
                            }
                        }
                    }
                },
                confirmButton = {}, dismissButton = { TextButton(onClick = vm::dismissPending) { Text("Abbrechen") } },
            )
        } else if (s.pendingMatches != null && s.pendingEan != null) {
            var bez by remember { mutableStateOf("Zeitschrift") }
            AlertDialog(
                onDismissRequest = vm::dismissPending,
                title = { Text("Unbekannter EAN") },
                text = {
                    Column {
                        Text("EAN ${s.pendingEan} unbekannt.")
                        s.pendingEanInfo?.preisBrutto?.let { Text("VKP aus EAN: %.2f €".format(it)) }
                        Spacer(Modifier.height(8.dp))
                        OutlinedTextField(value = bez, onValueChange = { bez = it }, label = { Text("Bezeichnung") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    }
                },
                confirmButton = { Button(onClick = { vm.createPending(bez) }) { Text("Anlegen") } },
                dismissButton = { TextButton(onClick = vm::dismissPending) { Text("Verwerfen") } },
            )
        }
    }
}
