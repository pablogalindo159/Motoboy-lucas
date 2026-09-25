package br.com.netpoint.rotas;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Build;
import android.os.Bundle;
import android.os.IBinder;
import android.os.Looper;
import android.webkit.CookieManager;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * Envia a localização do motoboy para o sistema mesmo com o app em segundo plano
 * (ex.: navegando no Waze). Fica com uma notificação fixa enquanto está ligado.
 */
public class RastreioService extends Service implements LocationListener {

    static volatile boolean ativo = false;

    private static final String CANAL = "rastreio";
    private static final long INTERVALO_MS = 20000;   // no máximo 1 envio a cada 20 s parado
    private static final float DISTANCIA_M = 30f;      // ou quando andar 30 m

    private LocationManager lm;
    private String csrf, api;
    private Location ultimo;
    private long ultimoEnvio;
    private final ExecutorService rede = Executors.newSingleThreadExecutor();

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent != null && intent.getStringExtra("api") != null) {
            csrf = intent.getStringExtra("csrf");
            api = intent.getStringExtra("api");
        }
        if (api == null) { stopSelf(); return START_NOT_STICKY; }

        Notification n = notificacao();
        if (Build.VERSION.SDK_INT >= 29) startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION);
        else startForeground(1, n);

        if (!ativo) {
            ativo = true;
            lm = (LocationManager) getSystemService(LOCATION_SERVICE);
            try { lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, 10000, 15f, this, Looper.getMainLooper()); } catch (Exception ignored) { }
            try { lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 30000, 50f, this, Looper.getMainLooper()); } catch (Exception ignored) { }
        }
        return START_REDELIVER_INTENT;
    }

    private Notification notificacao() {
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (Build.VERSION.SDK_INT >= 26 && nm.getNotificationChannel(CANAL) == null) {
            NotificationChannel c = new NotificationChannel(CANAL, "Localização em rota", NotificationManager.IMPORTANCE_LOW);
            c.setDescription("Mostra que o NetPoint Rotas está enviando sua localização");
            nm.createNotificationChannel(c);
        }
        Intent abrir = new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent pi = PendingIntent.getActivity(this, 0, abrir, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, CANAL) : new Notification.Builder(this);
        return b.setContentTitle("NetPoint Rotas")
                .setContentText("Enviando sua localização enquanto você está em rota")
                .setSmallIcon(android.R.drawable.ic_menu_mylocation)
                .setOngoing(true)
                .setContentIntent(pi)
                .build();
    }

    @Override
    public void onLocationChanged(Location l) {
        long agora = System.currentTimeMillis();
        // prefere o GPS: ignora a leitura da rede se tiver GPS recente
        if (ultimo != null && LocationManager.NETWORK_PROVIDER.equals(l.getProvider())
                && LocationManager.GPS_PROVIDER.equals(ultimo.getProvider()) && agora - ultimoEnvio < 60000) return;
        if (ultimo != null && agora - ultimoEnvio < INTERVALO_MS && l.distanceTo(ultimo) < DISTANCIA_M) return;
        ultimo = l;
        ultimoEnvio = agora;
        final double lat = l.getLatitude(), lng = l.getLongitude();
        rede.execute(() -> enviar(lat, lng));
    }

    private void enviar(double lat, double lng) {
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(api).openConnection();
            c.setRequestMethod("POST");
            c.setConnectTimeout(10000);
            c.setReadTimeout(10000);
            c.setDoOutput(true);
            String cookie = CookieManager.getInstance().getCookie(api);
            if (cookie != null) c.setRequestProperty("Cookie", cookie);
            if (csrf != null) c.setRequestProperty("X-CSRF", csrf);
            c.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
            String corpo = "acao=localizacao&lat=" + URLEncoder.encode(String.valueOf(lat), "UTF-8")
                    + "&lng=" + URLEncoder.encode(String.valueOf(lng), "UTF-8");
            try (OutputStream o = c.getOutputStream()) { o.write(corpo.getBytes(StandardCharsets.UTF_8)); }
            int codigo = c.getResponseCode();
            if (codigo == 401 || codigo == 403) stopSelf(); // sessão acabou: o motoboy precisa entrar de novo
        } catch (Exception ignored) {
            // sem internet: tenta na próxima leitura
        } finally {
            if (c != null) c.disconnect();
        }
    }

    @Override public void onProviderEnabled(String p) { }
    @Override public void onProviderDisabled(String p) { }
    @Override public void onStatusChanged(String p, int s, Bundle e) { }

    @Override
    public void onDestroy() {
        ativo = false;
        if (lm != null) try { lm.removeUpdates(this); } catch (Exception ignored) { }
        rede.shutdown();
        if (Build.VERSION.SDK_INT >= 24) stopForeground(STOP_FOREGROUND_REMOVE);
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent i) { return null; }
}
