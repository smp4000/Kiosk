package com.aral.kiosk.data.api

import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Query

interface KioskApi {

    @GET("api/ping.php")
    suspend fun ping(): PingResponse

    @GET("api/articles/lookup.php")
    suspend fun lookupByEan(@Query("ean") ean: String): LookupResponse

    @GET("api/articles/by-objekt.php")
    suspend fun byObjekt(
        @Query("objekt") objekt: String,
        @Query("supplier") supplier: String = "PVG",
    ): ByObjektResponse

    @POST("api/articles/upsert-pending.php")
    suspend fun upsertPending(@Body req: UpsertPendingRequest): UpsertPendingResponse

    @POST("api/deliveries/save.php")
    suspend fun saveDelivery(
        @Body req: SaveDeliveryRequest,
        @Header("X-Mitarbeiter") mitarbeiter: String? = null,
    ): SaveResponse

    @POST("api/remissions/save.php")
    suspend fun saveRemission(
        @Body req: SaveRemissionRequest,
        @Header("X-Mitarbeiter") mitarbeiter: String? = null,
    ): SaveResponse

    @POST("api/inventory/save.php")
    suspend fun saveInventory(
        @Body req: SaveInventoryRequest,
        @Header("X-Mitarbeiter") mitarbeiter: String? = null,
    ): SaveResponse
}
