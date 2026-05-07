package com.aral.kiosk.ui.screens.lieferung

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
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import com.aral.kiosk.data.api.Article
import com.aral.kiosk.ui.components.*

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LieferungScreen(nav: NavController, vm: LieferungViewModel = viewModel()) {
    val s by vm.state.collectAsState()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Lieferung") },
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
                Button(
                    onClick = { vm.save() },
                    enabled = !s.saving && s.positionen.isNotEmpty(),
                    modifier = Modifier.padding(end = 12.dp),
                ) { Text(if (s.saving) "Speichere…" else "Speichern") }
            }
        }
    ) { p ->
        Column(
            modifier = Modifier.padding(p).padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = s.lieferscheinNr, onValueChange = vm::setLieferscheinNr,
                    label = { Text("Lieferschein-Nr") }, singleLine = true,
                    modifier = Modifier.weight(1f),
                )
                OutlinedTextField(
                    value = s.lieferscheinDatum, onValueChange = vm::setLieferscheinDatum,
                    label = { Text("Datum") }, singleLine = true,
                    modifier = Modifier.weight(1f),
                )
            }

            ScanField(onScan = vm::onScan, placeholder = "EAN scannen oder Objekt-Nr eingeben")

            s.message?.let {
                StatusBox(it, color = if (s.isError) MaterialTheme.colorScheme.error else Color(0xFF15803D))
            }

            HorizontalDivider()

            if (s.positionen.isEmpty()) {
                Text("Noch keine Positionen.", color = MaterialTheme.colorScheme.outline)
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.weight(1f)) {
                    itemsIndexed(s.positionen) { idx, pos ->
                        ArticleCard(article = pos.article) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                AusgabePicker(
                                    ausgaben = (pos.article.ausgaben + pos.ausgabe).filterNotNull().distinct(),
                                    selected = pos.ausgabe,
                                    onSelect = { vm.changeAusgabe(idx, it) },
                                )
                                Spacer(Modifier.weight(1f))
                                MengenStepper(value = pos.menge, onChange = { vm.changeMenge(idx, it) })
                                Spacer(Modifier.width(4.dp))
                                IconButton(onClick = { vm.changeMenge(idx, 0) }) {
                                    Icon(Icons.Default.Delete, contentDescription = "entfernen")
                                }
                            }
                        }
                    }
                }
            }
        }

        // Wochentag-Dialog bei Mehrfach-Treffer
        s.pending?.let { p ->
            if (p.matches.size > 1) {
                MultiMatchDialog(p.matches, onPick = vm::choosePending, onDismiss = vm::dismissPending)
            } else if (p.matches.isEmpty()) {
                UnknownEanDialog(p.ean, p.eanInfo?.preisBrutto, onCreate = vm::createPendingArticle, onDismiss = vm::dismissPending)
            }
        }
    }
}

@Composable
private fun MultiMatchDialog(matches: List<Article>, onPick: (Article) -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Wochentag / Variante wählen") },
        text = {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(matches) { a ->
                    Card(onClick = { onPick(a) }) {
                        Column(modifier = Modifier.padding(12.dp)) {
                            Text("${a.bezeichnung}  (${weekdayLabel(a.weekday)})", style = MaterialTheme.typography.titleSmall)
                            Text("Obj ${a.objekt} • VKP %.2f €".format(a.vkpBrutto), color = MaterialTheme.colorScheme.outline)
                        }
                    }
                }
            }
        },
        confirmButton = {},
        dismissButton = { TextButton(onClick = onDismiss) { Text("Abbrechen") } },
    )
}

@Composable
private fun UnknownEanDialog(
    ean: String,
    vkp: Double?,
    onCreate: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    var bez by remember { mutableStateOf("Zeitschrift") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Unbekannter EAN") },
        text = {
            Column {
                Text("EAN $ean ist nicht in der Datenbank.")
                vkp?.let { Text("Aus EAN abgeleitet: VKP %.2f €".format(it)) }
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = bez, onValueChange = { bez = it },
                    label = { Text("Bezeichnung") }, singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = { Button(onClick = { onCreate(bez) }) { Text("Anlegen & übernehmen") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Verwerfen") } },
    )
}
