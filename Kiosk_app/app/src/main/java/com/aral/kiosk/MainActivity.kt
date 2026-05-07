package com.aral.kiosk

import android.os.Bundle
import android.view.KeyEvent
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.aral.kiosk.data.scanner.ScannerBridge
import com.aral.kiosk.ui.screens.home.HomeScreen
import com.aral.kiosk.ui.screens.inventur.InventurScreen
import com.aral.kiosk.ui.screens.lieferung.LieferungScreen
import com.aral.kiosk.ui.screens.remission.RemissionScreen
import com.aral.kiosk.ui.screens.settings.SettingsScreen
import com.aral.kiosk.ui.theme.KioskTheme

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            KioskTheme {
                Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
                    KioskNavGraph()
                }
            }
        }
    }

    override fun dispatchKeyEvent(event: KeyEvent): Boolean {
        if (ScannerBridge.onKeyEvent(event)) return true
        return super.dispatchKeyEvent(event)
    }
}

object Routes {
    const val HOME = "home"
    const val SETTINGS = "settings"
    const val LIEFERUNG = "lieferung"
    const val REMISSION = "remission"
    const val INVENTUR = "inventur"
}

@androidx.compose.runtime.Composable
fun KioskNavGraph() {
    val nav = rememberNavController()
    NavHost(navController = nav, startDestination = Routes.HOME) {
        composable(Routes.HOME)      { HomeScreen(nav) }
        composable(Routes.SETTINGS)  { SettingsScreen(nav) }
        composable(Routes.LIEFERUNG) { LieferungScreen(nav) }
        composable(Routes.REMISSION) { RemissionScreen(nav) }
        composable(Routes.INVENTUR)  { InventurScreen(nav) }
    }
}
