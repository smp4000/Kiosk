package com.aral.kiosk.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.aral.kiosk.data.api.Article
import com.aral.kiosk.data.scanner.ScannerBridge
import kotlinx.coroutines.flow.collectLatest

private val WeekdayLabels = mapOf(1 to "Mo", 2 to "Di", 3 to "Mi", 4 to "Do", 5 to "Fr", 6 to "Sa", 7 to "So")

fun weekdayLabel(d: Int?): String = WeekdayLabels[d] ?: "–"

@Composable
fun ScanField(
    onScan: (String) -> Unit,
    placeholder: String = "EAN scannen oder eingeben",
) {
    var text by remember { mutableStateOf("") }
    val keyboardController = LocalSoftwareKeyboardController.current

    LaunchedEffect(Unit) {
        ScannerBridge.scans.collectLatest { code ->
            onScan(code)
        }
    }

    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
        OutlinedTextField(
            value = text,
            onValueChange = { text = it.filter { c -> c.isDigit() } },
            placeholder = { Text(placeholder) },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            modifier = Modifier.weight(1f),
        )
        Spacer(Modifier.width(8.dp))
        Button(onClick = {
            if (text.isNotBlank()) {
                keyboardController?.hide()
                onScan(text.trim())
                text = ""
            }
        }) { Text("OK") }
    }
}

@Composable
fun MengenStepper(
    value: Int,
    onChange: (Int) -> Unit,
    minValue: Int = 1,
    maxValue: Int = 999,
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        FilledIconButton(onClick = { if (value > minValue) onChange(value - 1) }) {
            Icon(Icons.Default.Remove, contentDescription = "minus")
        }
        Text(
            text = value.toString(),
            fontSize = 22.sp,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.padding(horizontal = 16.dp).widthIn(min = 36.dp),
        )
        FilledIconButton(onClick = { if (value < maxValue) onChange(value + 1) }) {
            Icon(Icons.Default.Add, contentDescription = "plus")
        }
    }
}

@Composable
fun ArticleCard(
    article: Article,
    showVkp: Boolean = true,
    trailing: (@Composable () -> Unit)? = null,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = if (article.isPending) Color(0xFFFEF3C7) else MaterialTheme.colorScheme.surface,
        )
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = article.bezeichnung,
                    fontWeight = FontWeight.SemiBold,
                    fontSize = 16.sp,
                    modifier = Modifier.weight(1f),
                )
                if (article.isPending) {
                    Badge { Text("Pending") }
                }
            }
            Spacer(Modifier.height(4.dp))
            Row {
                Text("EAN ${article.ean}", fontSize = 12.sp, color = MaterialTheme.colorScheme.outline)
                Spacer(Modifier.width(12.dp))
                Text("Obj ${article.objekt}", fontSize = 12.sp, color = MaterialTheme.colorScheme.outline)
                article.weekday?.let {
                    Spacer(Modifier.width(12.dp))
                    Text(weekdayLabel(it), fontSize = 12.sp, color = MaterialTheme.colorScheme.outline)
                }
            }
            if (showVkp) {
                Spacer(Modifier.height(6.dp))
                Row {
                    Text(
                        "VKP %.2f €".format(article.vkpBrutto),
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.primary,
                    )
                    Spacer(Modifier.width(12.dp))
                    article.ek?.let {
                        Text("EK %.4f €".format(it), color = MaterialTheme.colorScheme.outline, fontSize = 12.sp)
                    }
                    Spacer(Modifier.width(12.dp))
                    Text("MwSt %.0f%%".format(article.mwstSatz), color = MaterialTheme.colorScheme.outline, fontSize = 12.sp)
                }
            }
            if (trailing != null) {
                Spacer(Modifier.height(8.dp))
                trailing()
            }
        }
    }
}

@Composable
fun WeekdayPicker(
    weekdays: List<Int>,
    onPicked: (Int) -> Unit,
    onDismiss: () -> Unit,
    title: String = "Wochentag wählen",
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                weekdays.forEach { d ->
                    Button(onClick = { onPicked(d) }, contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp)) {
                        Text(weekdayLabel(d))
                    }
                }
            }
        },
        confirmButton = {},
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("Abbrechen") }
        }
    )
}

@Composable
fun AusgabePicker(
    ausgaben: List<String>,
    selected: String?,
    onSelect: (String) -> Unit,
) {
    if (ausgaben.isEmpty()) return
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        ausgaben.take(5).forEach { ag ->
            FilterChip(
                selected = ag == selected,
                onClick = { onSelect(ag) },
                label = { Text("KW $ag") },
            )
        }
    }
}

@Composable
fun StatusBox(text: String, color: Color) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .background(color.copy(alpha = 0.15f), RoundedCornerShape(6.dp))
            .border(1.dp, color, RoundedCornerShape(6.dp))
            .padding(10.dp)
    ) {
        Text(text, color = color)
    }
}
