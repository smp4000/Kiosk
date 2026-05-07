package com.aral.kiosk.ui.screens.home

import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Inventory
import androidx.compose.material.icons.filled.LocalShipping
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Undo
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import com.aral.kiosk.Routes

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(nav: NavController) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Kiosk") },
                actions = {
                    IconButton(onClick = { nav.navigate(Routes.SETTINGS) }) {
                        Icon(Icons.Default.Settings, contentDescription = "Einstellungen")
                    }
                }
            )
        }
    ) { p ->
        Column(
            modifier = Modifier.padding(p).padding(16.dp).fillMaxSize(),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text(
                "Was möchtest du erfassen?",
                fontSize = 18.sp,
                fontWeight = FontWeight.SemiBold,
            )
            BigButton("Lieferung", "Wareneingang erfassen", Icons.Default.LocalShipping) {
                nav.navigate(Routes.LIEFERUNG)
            }
            BigButton("Remission", "Pakete für Rückgabe erfassen", Icons.Default.Undo) {
                nav.navigate(Routes.REMISSION)
            }
            BigButton("Inventur", "Bestand erfassen", Icons.Default.Inventory) {
                nav.navigate(Routes.INVENTUR)
            }
        }
    }
}

@Composable
private fun BigButton(title: String, subtitle: String, icon: ImageVector, onClick: () -> Unit) {
    Card(modifier = Modifier.fillMaxWidth(), onClick = onClick) {
        Row(
            modifier = Modifier.padding(20.dp).fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(40.dp), tint = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.width(16.dp))
            Column {
                Text(title, fontSize = 22.sp, fontWeight = FontWeight.Bold)
                Text(subtitle, fontSize = 14.sp, color = MaterialTheme.colorScheme.outline)
            }
        }
    }
}
