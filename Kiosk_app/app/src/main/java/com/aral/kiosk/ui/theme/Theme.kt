package com.aral.kiosk.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val KioskBlue       = Color(0xFF2563EB)
private val KioskBlueDark   = Color(0xFF1D4ED8)
private val KioskAmber      = Color(0xFFF59E0B)

private val LightColors = lightColorScheme(
    primary = KioskBlue,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFDBEAFE),
    onPrimaryContainer = KioskBlueDark,
    secondary = KioskAmber,
    onSecondary = Color.Black,
    background = Color(0xFFF5F7FA),
    surface = Color.White,
    error = Color(0xFFB91C1C),
)

private val DarkColors = darkColorScheme(
    primary = KioskBlue,
    secondary = KioskAmber,
)

@Composable
fun KioskTheme(content: @Composable () -> Unit) {
    val colors = if (isSystemInDarkTheme()) DarkColors else LightColors
    MaterialTheme(colorScheme = colors, content = content)
}
