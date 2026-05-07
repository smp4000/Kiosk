package com.aral.kiosk.data.scanner

import android.view.KeyEvent
import kotlinx.coroutines.channels.BufferOverflow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow

/**
 * Empfängt KeyEvents von der Activity (Hardware-Scanner / Keyboard-Wedge),
 * sammelt sie bis Newline / Enter, emittiert dann den vollständigen Code.
 *
 * Funktioniert mit Zebra (Keyboard-Wedge-Modus), Munbyn, Netum und jedem anderen
 * Bluetooth-/USB-Scanner, der als HID-Tastatur betrieben wird.
 */
object ScannerBridge {

    private val buffer = StringBuilder()
    private var lastEventAt = 0L
    private const val BURST_GAP_MS = 400L

    private val _scans = MutableSharedFlow<String>(
        extraBufferCapacity = 8,
        onBufferOverflow = BufferOverflow.DROP_OLDEST,
    )
    val scans: SharedFlow<String> = _scans.asSharedFlow()

    /**
     * Wird aus MainActivity.dispatchKeyEvent() aufgerufen.
     * @return true wenn das Event vom Scanner kommt und konsumiert wurde.
     */
    fun onKeyEvent(event: KeyEvent): Boolean {
        if (event.action != KeyEvent.ACTION_DOWN) return false

        val now = System.currentTimeMillis()
        // Wenn länger als BURST_GAP keine Eingabe → Buffer zurücksetzen
        if (now - lastEventAt > BURST_GAP_MS) {
            buffer.clear()
        }
        lastEventAt = now

        return when (event.keyCode) {
            KeyEvent.KEYCODE_ENTER, KeyEvent.KEYCODE_NUMPAD_ENTER -> {
                val code = buffer.toString().trim()
                buffer.clear()
                if (code.isNotEmpty()) {
                    _scans.tryEmit(code)
                    true
                } else false
            }
            KeyEvent.KEYCODE_TAB -> {
                val code = buffer.toString().trim()
                buffer.clear()
                if (code.isNotEmpty()) {
                    _scans.tryEmit(code)
                    true
                } else false
            }
            else -> {
                val ch = event.unicodeChar
                if (ch != 0) {
                    buffer.append(ch.toChar())
                }
                // Wir lassen das Event durch, damit normale Tastaturen
                // (z. B. Soft-Keyboard) weiter funktionieren.
                false
            }
        }
    }

    fun emitManual(code: String) {
        val trimmed = code.trim()
        if (trimmed.isNotEmpty()) _scans.tryEmit(trimmed)
    }
}
