package br.com.netpoint.rotas;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.content.pm.ServiceInfo;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Build;
import android.os.Bundle;
import android.os.IBinder;
import android.os.Looper;
import android.webkit.CookieManager;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

/**
 * Serviço do NetPoint Rotas (fica ligado com o app fechado):
 *  - confere os avisos no servidor a cada 45 s e mostra notificação no celular;
 *  - durante a rota, também envia a localização (mesmo com Waze/Maps na frente).
 */
public class NetPointService extends Service implements LocationListener {

    static volatile boolean ligado = false;
    static volatile boolean rastreando = false;

    private static final String CANAL_SERVICO = "servico";
    private static final String CANAL_AVISOS = "avisos";
    private static final int ID_FIXA = 1;
    private static final long AVISOS_SEG = 45;
    private static final long INTERVALO_MS = 20000;
    private static final float DISTANCIA_M = 30f;

    private SharedPreferences prefs;
    private String api, csrf;
    private long ultimoAviso;
    private ScheduledExecutorService agenda;
    private LocationManager lm;
    private Location ultimo;
    private long ultimoEnvio;

    static void comando(Context c, String acao, String api, String csrf, long ultimoAviso) {
        Intent i = new Intent(c, NetPointService.class).putExtra("acao", acao);
        if (api != null) i.putExtra("api", api);
        if (csrf != null) i.putExtra("csrf", csrf);
        if (ultimoAviso > 0) i.putExtra("ultimo", ultimoAviso);
        try {
            if (Build.VERSION.SDK_INT >= 26) c.startForegroundService(i); else c.startService(i);
        } catch (Exception ignored) { }
    }

    @Override
    public void onCreate() {
        super.onCreate();
        prefs = getSharedPreferences("netpoint", MODE_PRIVATE);
        api = prefs.getString("api", null);
        csrf = prefs.getString("csrf", null);
        ultimoAviso = prefs.getLong("ultimo", 0);
        criarCanais();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String acao = intent != null && intent.getStringExtra("acao") != null ? intent.getStringExtra("acao") : "avisos";
        if (intent != null) {
            if (intent.getStringExtra("api") != null) api = intent.getStringExtra("api");
            if (intent.getStringExtra("csrf") != null) csrf = intent.getStringExtra("csrf");
            long u = intent.getLongExtra("ultimo", 0);
            if (u > ultimoAviso) ultimoAviso = u;
            prefs.edit().putString("api", api).putString("csrf", csrf).putLong("ultimo", ultimoAviso).apply();
        }
        if ("parar".equals(acao) || api == null) { pararTudo(); return START_NOT_STICKY; }

        if ("rastreio_on".equals(acao)) ligarGps();
        else if ("rastreio_off".equals(acao)) desligarGps();

        entrarEmPrimeiroPlano();
        ligado = true;
        if (agenda == null) {
            agenda = Executors.newSingleThreadScheduledExecutor();
            agenda.scheduleWithFixedDelay(this::conferirAvisos, 3, AVISOS_SEG, TimeUnit.SECONDS);
        }
        return START_STICKY;
    }

    // ---------- notificação fixa ----------
    private void criarCanais() {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (nm.getNotificationChannel(CANAL_SERVICO) == null) {
            NotificationChannel c = new NotificationChannel(CANAL_SERVICO, "NetPoint conectado", NotificationManager.IMPORTANCE_MIN);
            c.setDescription("Mantém o app recebendo avisos e enviando a localização durante a rota");
            nm.createNotificationChannel(c);
        }
        if (nm.getNotificationChannel(CANAL_AVISOS) == null) {
            NotificationChannel c = new NotificationChannel(CANAL_AVISOS, "Avisos da rota", NotificationManager.IMPORTANCE_HIGH);
            c.setDescription("Rota disponível, ambulância, entregas novas e outros avisos");
            c.enableVibration(true);
            nm.createNotificationChannel(c);
        }
    }

    private PendingIntent abrirApp(int req, String link) {
        Intent abrir = new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        if (link != null) abrir.putExtra("link", link);
        return PendingIntent.getActivity(this, req, abrir, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
    }

    private void entrarEmPrimeiroPlano() {
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, CANAL_SERVICO) : new Notification.Builder(this);
        Notification n = b.setContentTitle("NetPoint Rotas")
                .setContentText(rastreando ? "Em rota: enviando sua localização e recebendo avisos" : "Conectado: você recebe os avisos da rota")
                .setSmallIcon(rastreando ? android.R.drawable.ic_menu_mylocation : android.R.drawable.ic_popup_reminder)
                .setOngoing(true)
                .setContentIntent(abrirApp(0, null))
                .build();
        try {
            if (Build.VERSION.SDK_INT >= 34) {
                int tipo = ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE;
                if (rastreando) tipo |= ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION;
                startForeground(ID_FIXA, n, tipo);
            } else if (Build.VERSION.SDK_INT >= 29) {
                startForeground(ID_FIXA, n, rastreando ? ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION : 0);
            } else {
                startForeground(ID_FIXA, n);
            }
        } catch (Exception e) {
            // sem permissão de localização: fica só com os avisos
            if (rastreando) { desligarGps(); entrarEmPrimeiroPlano(); }
        }
    }

    // ---------- avisos ----------
    private void conferirAvisos() {
        if (api == null) return;
        HttpURLConnection c = null;
        try {
            String url = api + (api.contains("?") ? "&" : "?") + "acao=avisos&desde=" + ultimoAviso;
            c = (HttpURLConnection) new URL(url).openConnection();
            c.setConnectTimeout(10000);
            c.setReadTimeout(10000);
            String cookie = CookieManager.getInstance().getCookie(api);
            if (cookie != null) c.setRequestProperty("Cookie", cookie);
            int codigo = c.getResponseCode();
            if (codigo == 401 || codigo == 403) { pararTudo(); return; } // saiu do sistema
            if (codigo != 200) return;
            JSONObject j = new JSONObject(ler(c.getInputStream()));
            long novoUltimo = j.optLong("ultimo", ultimoAviso);
            if (ultimoAviso > 0) {
                JSONArray lista = j.optJSONArray("avisos");
                if (lista != null) for (int i = 0; i < lista.length(); i++) mostrarAviso(lista.getJSONObject(i));
            }
            if (novoUltimo > ultimoAviso) { ultimoAviso = novoUltimo; prefs.edit().putLong("ultimo", ultimoAviso).apply(); }
        } catch (Exception ignored) {
            // sem internet: tenta de novo na próxima
        } finally {
            if (c != null) c.disconnect();
        }
    }

    private void mostrarAviso(JSONObject a) {
        int id = a.optInt("id", (int) (System.currentTimeMillis() % 100000));
        boolean alta = "alta".equals(a.optString("prioridade"));
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, CANAL_AVISOS) : new Notification.Builder(this);
        b.setContentTitle(a.optString("titulo", "NetPoint Rotas"))
                .setContentText(a.optString("texto", ""))
                .setStyle(new Notification.BigTextStyle().bigText(a.optString("texto", "")))
                .setSmallIcon(android.R.drawable.ic_dialog_info)
                .setAutoCancel(true)
                .setContentIntent(abrirApp(id, a.isNull("link") ? null : a.optString("link", null)));
        if (Build.VERSION.SDK_INT < 26) b.setPriority(alta ? Notification.PRIORITY_HIGH : Notification.PRIORITY_DEFAULT).setDefaults(Notification.DEFAULT_ALL);
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return;
        ((NotificationManager) getSystemService(NOTIFICATION_SERVICE)).notify(1000 + id, b.build());
    }

    private static String ler(InputStream in) throws Exception {
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        byte[] buf = new byte[4096];
        int n;
        while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
        return out.toString("UTF-8");
    }

    // ---------- GPS durante a rota ----------
    private boolean temLocalizacao() {
        return checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
                || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    private void ligarGps() {
        if (rastreando || !temLocalizacao()) return;
        rastreando = true;
        lm = (LocationManager) getSystemService(LOCATION_SERVICE);
        try { lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, 10000, 15f, this, Looper.getMainLooper()); } catch (Exception ignored) { }
        try { lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 30000, 50f, this, Looper.getMainLooper()); } catch (Exception ignored) { }
    }

    private void desligarGps() {
        rastreando = false;
        if (lm != null) try { lm.removeUpdates(this); } catch (Exception ignored) { }
    }

    @Override
    public void onLocationChanged(Location l) {
        long agora = System.currentTimeMillis();
        if (ultimo != null && LocationManager.NETWORK_PROVIDER.equals(l.getProvider())
                && LocationManager.GPS_PROVIDER.equals(ultimo.getProvider()) && agora - ultimoEnvio < 60000) return;
        if (ultimo != null && agora - ultimoEnvio < INTERVALO_MS && l.distanceTo(ultimo) < DISTANCIA_M) return;
        ultimo = l;
        ultimoEnvio = agora;
        final double lat = l.getLatitude(), lng = l.getLongitude();
        ScheduledExecutorService ag = agenda;
        if (ag != null) ag.execute(() -> enviarPosicao(lat, lng));
    }

    private void enviarPosicao(double lat, double lng) {
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
            if (codigo == 401 || codigo == 403) pararTudo();
        } catch (Exception ignored) {
        } finally {
            if (c != null) c.disconnect();
        }
    }

    @Override public void onProviderEnabled(String p) { }
    @Override public void onProviderDisabled(String p) { }
    @Override public void onStatusChanged(String p, int s, Bundle e) { }

    private void pararTudo() {
        desligarGps();
        ligado = false;
        if (agenda != null) { agenda.shutdownNow(); agenda = null; }
        prefs.edit().remove("api").apply();
        if (Build.VERSION.SDK_INT >= 24) stopForeground(STOP_FOREGROUND_REMOVE);
        stopSelf();
    }

    @Override
    public void onDestroy() {
        desligarGps();
        ligado = false;
        if (agenda != null) { agenda.shutdownNow(); agenda = null; }
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent i) { return null; }
}
