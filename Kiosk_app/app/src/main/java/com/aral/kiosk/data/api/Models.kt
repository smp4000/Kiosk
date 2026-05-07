package com.aral.kiosk.data.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class PingResponse(
    val ok: Boolean,
    val service: String? = null,
    val version: String? = null,
    val timestamp: String? = null,
    val articles: Int? = null,
    val error: String? = null,
)

@Serializable
data class Article(
    val id: Int,
    val supplier: String,
    val objekt: String,
    val ean: String,
    val weekday: Int? = null,
    val bezeichnung: String,
    @SerialName("aktueller_preis_netto")  val vkpNetto: Double = 0.0,
    @SerialName("aktueller_preis_brutto") val vkpBrutto: Double = 0.0,
    @SerialName("mwst_satz")              val mwstSatz: Double = 0.0,
    val ek: Double? = null,
    @SerialName("is_pending") val isPending: Boolean = false,
    @SerialName("last_seen_at") val lastSeenAt: String? = null,
    val ausgaben: List<String> = emptyList(),
)

@Serializable
data class EanInfo(
    @SerialName("is_press")     val isPress: Boolean = false,
    @SerialName("mwst_satz")    val mwstSatz: Double? = null,
    @SerialName("preis_brutto") val preisBrutto: Double? = null,
    @SerialName("jugendschutz") val jugendschutz: Boolean? = null,
    @SerialName("check_valid")  val checkValid: Boolean? = null,
)

@Serializable
data class LookupResponse(
    val ok: Boolean,
    val ean: String,
    val count: Int = 0,
    val articles: List<Article> = emptyList(),
    @SerialName("ean_info") val eanInfo: EanInfo? = null,
    val error: String? = null,
)

@Serializable
data class ByObjektResponse(
    val ok: Boolean,
    val article: Article? = null,
    val error: String? = null,
)

@Serializable
data class UpsertPendingRequest(
    val ean: String,
    val bezeichnung: String? = null,
    val weekday: Int? = null,
)

@Serializable
data class UpsertPendingResponse(
    val ok: Boolean,
    val created: Boolean = false,
    @SerialName("article_id") val articleId: Int = 0,
    @SerialName("is_pending") val isPending: Boolean = true,
    @SerialName("preis_brutto") val preisBrutto: Double? = null,
    @SerialName("mwst_satz") val mwstSatz: Double? = null,
    val error: String? = null,
)

@Serializable
data class SaveItem(
    @SerialName("article_id")  val articleId: Int,
    val ausgabe: String? = null,
    val menge: Int,
    @SerialName("vkp_brutto")  val vkpBrutto: Double? = null,
    @SerialName("mwst_satz")   val mwstSatz: Double? = null,
    @SerialName("scanned_ean") val scannedEan: String? = null,
)

@Serializable
data class SaveDeliveryRequest(
    @SerialName("lieferschein_nr")    val lieferscheinNr: String? = null,
    @SerialName("lieferschein_datum") val lieferscheinDatum: String? = null,
    val notiz: String? = null,
    val mitarbeiter: String? = null,
    @SerialName("station_id") val stationId: Int? = null,
    val items: List<SaveItem>,
)

@Serializable
data class SaveRemissionRequest(
    val paket: String? = null,
    @SerialName("paket_datum") val paketDatum: String? = null,
    val notiz: String? = null,
    val mitarbeiter: String? = null,
    @SerialName("station_id") val stationId: Int? = null,
    val items: List<SaveItem>,
)

@Serializable
data class SaveInventoryRequest(
    val bezeichnung: String? = null,
    val modus: String = "partial",   // "full" oder "partial"
    val stufe: Int = 1,
    val notiz: String? = null,
    val mitarbeiter: String? = null,
    @SerialName("station_id") val stationId: Int? = null,
    val items: List<SaveItem>,
)

@Serializable
data class SaveResponse(
    val ok: Boolean,
    @SerialName("delivery_id")      val deliveryId: Int? = null,
    @SerialName("remi_package_id")  val remiPackageId: Int? = null,
    @SerialName("inventory_run_id") val inventoryRunId: Int? = null,
    @SerialName("items_saved")      val itemsSaved: Int = 0,
    val error: String? = null,
)
