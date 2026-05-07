package com.aral.kiosk.ui.screens.settings

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import com.aral.kiosk.KioskApp
import com.aral.kiosk.ui.components.StatusBox

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(nav: NavController, vm: SettingsViewModel = viewModel()) {
    val state by vm.state.collectAsState()
    var url   by rememberSaveable { mutableStateOf("") }
    var emp   by rememberSaveable { mutableStateOf("") }
    var stat  by rememberSaveable { mutableStateOf("") }

    LaunchedEffect(state.loaded) {
        if (state.loaded) {
            url  = state.serverUrl
            emp  = state.mitarbeiter
            stat = state.stationId
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Einstellungen") },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "zurück")
                    }
                },
            )
        }
    ) { p ->
        Column(
            modifier = Modifier.padding(p).padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Server", style = MaterialTheme.typography.titleMedium)
            OutlinedTextField(
                value = url, onValueChange = { url = it },
                label = { Text("Server-URL") },
                supportingText = { Text("Beispiel: http://192.168.1.10/kiosk/  •  Emulator: http://10.0.2.2/kiosk/") },
                singleLine = true, modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = emp, onValueChange = { emp = it },
                label = { Text("Mitarbeiter (optional)") },
                singleLine = true, modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = stat, onValueChange = { stat = it.filter { c -> c.isDigit() } },
                label = { Text("Station-ID (optional)") },
                singleLine = true, modifier = Modifier.fillMaxWidth(),
            )

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { vm.save(url, emp, stat) }, modifier = Modifier.weight(1f)) {
                    Text("Speichern")
                }
                OutlinedButton(onClick = { vm.testConnection(url) }, modifier = Modifier.weight(1f)) {
                    Text("Testen")
                }
            }

            state.message?.let {
                StatusBox(it, color = if (state.isError) MaterialTheme.colorScheme.error else Color(0xFF15803D))
            }
        }
    }
}
