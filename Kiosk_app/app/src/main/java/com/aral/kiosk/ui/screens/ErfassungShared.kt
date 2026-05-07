package com.aral.kiosk.ui.screens

import com.aral.kiosk.data.api.Article
import com.aral.kiosk.data.api.SaveItem

/**
 * Eine erfasste Position in einer Lieferung / Remission / Inventur.
 * Im Speicher gehalten bis zum „Speichern“-Button.
 */
data class ErfasstePosition(
    val article: Article,
    val ausgabe: String?,
    val menge: Int,
    val scannedEan: String?,
) {
    fun toSaveItem(): SaveItem = SaveItem(
        articleId = article.id,
        ausgabe = ausgabe,
        menge = menge,
        vkpBrutto = article.vkpBrutto,
        mwstSatz = article.mwstSatz,
        scannedEan = scannedEan,
    )
}

/** "0018" → 18  ;  null/ungültig → null */
fun ausgabeToKw(s: String?): Int? {
    if (s == null) return null
    val n = s.trimStart('0').toIntOrNull() ?: return null
    return if (n in 1..53) n else null
}

/** Aktuelle KW als 4-stellige String-ID. */
fun currentKwString(): String {
    val cal = java.util.Calendar.getInstance()
    cal.minimalDaysInFirstWeek = 4
    cal.firstDayOfWeek = java.util.Calendar.MONDAY
    val kw = cal.get(java.util.Calendar.WEEK_OF_YEAR)
    return "%04d".format(kw)
}

/** Für Lieferungen: aktuelle KW. */
fun defaultLieferKw(): String = currentKwString()

/** Für Remissionen: typischerweise die Vorwoche. */
fun defaultRemiKw(): String {
    val cal = java.util.Calendar.getInstance()
    cal.minimalDaysInFirstWeek = 4
    cal.firstDayOfWeek = java.util.Calendar.MONDAY
    cal.add(java.util.Calendar.WEEK_OF_YEAR, -1)
    val kw = cal.get(java.util.Calendar.WEEK_OF_YEAR)
    return "%04d".format(kw)
}
