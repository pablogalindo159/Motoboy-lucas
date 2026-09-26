package br.com.netpoint.rotas;

import android.Manifest;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.JavascriptInterface;
import android.webkit.PermissionRequest;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import com.google.firebase.messaging.FirebaseMessaging;

import java.util.ArrayList;
import java.util.List;

/** Abre o NetPoint Rotas em tela cheia e liga o GPS em segundo plano quando o motoboy está em rota. */
public class MainActivity extends Activity {

    private WebView web;
    private String servidor;
    private GeolocationPermissions.Callback geoCallback;
    private String geoOrigem;
    private Intent rastreioPendente;
    private PermissionRequest cameraPendente;
    private volatile String tokenFcm;

    @Override
    protected void onCreate(Bundle salvo) {
        super.onCreate(salvo);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        servidor = getString(R.string.url_servidor);

        web = new WebView(this);
        setContentView(web);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setGeolocationEnabled(true);
        s.setSupportMultipleWindows(false);
        s.setMediaPlaybackRequiresUserGesture(false); // prévia da câmera do pacote voador
        s.setUserAgentString(s.getUserAgentString() + " NetPointApp/" + BuildConfigVersao());

        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false);
        web.addJavascriptInterface(new Ponte(), "NetPointApp");

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest req) {
                Uri u = req.getUrl();
                if (doServidor(u)) return false;
                abrirFora(u); // Google Maps, Waze, WhatsApp...
                return true;
            }

            @Override
            public void onPageFinished(WebView v, String url) {
                registrarTokenNaPagina();
            }

            @Override
            public void onReceivedError(WebView v, WebResourceRequest req, WebResourceError erro) {
                if (!req.isForMainFrame()) return;
                v.loadDataWithBaseURL(null,
                        "<html><body style='background:#000;color:#fff;font-family:sans-serif;text-align:center;padding-top:40vh'>"
                                + "<h2 style='color:#8CF20A'>Sem conexão</h2><p>Tentando de novo em alguns segundos…</p></body></html>",
                        "text/html", "utf-8", null);
                new Handler(Looper.getMainLooper()).postDelayed(() -> web.loadUrl(servidor), 5000);
            }
        });

        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onGeolocationPermissionsShowPrompt(String origem, GeolocationPermissions.Callback cb) {
                if (temLocalizacao()) {
                    cb.invoke(origem, true, false);
                } else {
                    geoCallback = cb;
                    geoOrigem = origem;
                    pedirPermissoes();
                }
            }

            // câmera para o "pacote voador" (só para o próprio sistema)
            @Override
            public void onPermissionRequest(final PermissionRequest req) {
                runOnUiThread(() -> {
                    boolean querCamera = false;
                    for (String r : req.getResources()) if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r)) querCamera = true;
                    if (!querCamera || !doServidor(req.getOrigin())) { req.deny(); return; }
                    if (checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                        req.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
                    } else {
                        cameraPendente = req;
                        requestPermissions(new String[]{Manifest.permission.CAMERA}, 2);
                    }
                });
            }
        });

        NetPointService.criarCanais(this);
        pegarTokenFirebase();
        pedirPermissoes();
        if (salvo != null) web.restoreState(salvo);
        else if (getIntent() != null && getIntent().getStringExtra("link") != null) abrirLinkDoAviso(getIntent());
        else web.loadUrl(servidor);
    }

    private String BuildConfigVersao() {
        try {
            return getPackageManager().getPackageInfo(getPackageName(), 0).versionName;
        } catch (Exception e) {
            return "1";
        }
    }

    private boolean doServidor(Uri u) {
        Uri base = Uri.parse(servidor);
        return u.getHost() != null && u.getHost().equals(base.getHost());
    }

    private void abrirFora(Uri u) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, u));
        } catch (ActivityNotFoundException e) {
            // nenhum app para abrir: ignora
        }
    }

    private boolean temLocalizacao() {
        return checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
                || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    private void pedirPermissoes() {
        List<String> faltam = new ArrayList<>();
        if (!temLocalizacao()) {
            faltam.add(Manifest.permission.ACCESS_FINE_LOCATION);
            faltam.add(Manifest.permission.ACCESS_COARSE_LOCATION);
        }
        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            faltam.add(Manifest.permission.POST_NOTIFICATIONS);
        }
        if (!faltam.isEmpty()) requestPermissions(faltam.toArray(new String[0]), 1);
    }

    @Override
    public void onRequestPermissionsResult(int codigo, String[] perms, int[] res) {
        super.onRequestPermissionsResult(codigo, perms, res);
        if (codigo == 2 && cameraPendente != null) {
            if (checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED)
                cameraPendente.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
            else cameraPendente.deny();
            cameraPendente = null;
            return;
        }
        boolean ok = temLocalizacao();
        if (geoCallback != null) {
            geoCallback.invoke(geoOrigem, ok, false);
            geoCallback = null;
        }
        if (ok && rastreioPendente != null) {
            iniciarServico(rastreioPendente);
            rastreioPendente = null;
        }
    }

    private void iniciarServico(Intent i) {
        try {
            if (Build.VERSION.SDK_INT >= 26) startForegroundService(i);
            else startService(i);
        } catch (Exception e) {
            // app em segundo plano: tenta de novo no próximo carregamento da página
        }
    }

    // ---------- Firebase ----------
    private void pegarTokenFirebase() {
        try {
            FirebaseMessaging.getInstance().getToken().addOnCompleteListener(t -> {
                if (t.isSuccessful() && t.getResult() != null) {
                    tokenFcm = t.getResult();
                    getSharedPreferences("netpoint", MODE_PRIVATE).edit().putString("fcm_token", tokenFcm).putBoolean("fcm_ativo", true).apply();
                    runOnUiThread(this::registrarTokenNaPagina);
                }
            });
        } catch (Exception e) {
            // app gerado sem o google-services.json: segue com o aviso a cada ~45 s
            getSharedPreferences("netpoint", MODE_PRIVATE).edit().putBoolean("fcm_ativo", false).apply();
        }
    }

    private void registrarTokenNaPagina() {
        if (tokenFcm == null || web == null) return;
        web.evaluateJavascript("window.registrarTokenFcm && window.registrarTokenFcm(" + org.json.JSONObject.quote(tokenFcm) + ")", null);
    }

    // toque numa notificação de aviso: abre a página do aviso
    private void abrirLinkDoAviso(Intent intent) {
        String link = intent != null ? intent.getStringExtra("link") : null;
        if (link != null && web != null && !link.startsWith("http")) web.loadUrl(servidor + link.replaceFirst("^/", ""));
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        abrirLinkDoAviso(intent);
    }

    @Override
    protected void onSaveInstanceState(Bundle estado) {
        super.onSaveInstanceState(estado);
        web.saveState(estado);
    }

    @Override
    protected void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }

    @Override
    public void onBackPressed() {
        if (web.canGoBack()) web.goBack();
        else moveTaskToBack(true); // não fecha o app (o GPS continua)
    }

    /** Funções que a página do motoboy chama (window.NetPointApp). */
    private class Ponte {
        @JavascriptInterface
        public void iniciarRastreio(String csrf, String urlApi) {
            Intent i = new Intent(MainActivity.this, NetPointService.class)
                    .putExtra("acao", "rastreio_on").putExtra("csrf", csrf).putExtra("api", urlApi);
            runOnUiThread(() -> {
                if (temLocalizacao()) iniciarServico(i);
                else { rastreioPendente = i; pedirPermissoes(); }
            });
        }

        @JavascriptInterface
        public void pararRastreio() {
            if (NetPointService.ligado) runOnUiThread(() -> NetPointService.comando(MainActivity.this, "rastreio_off", null, null, 0));
        }

        /** Liga as notificações de avisos (funcionam com o app fechado). */
        @JavascriptInterface
        public void ligarAvisos(String urlApi, double ultimoAviso) {
            runOnUiThread(() -> NetPointService.comando(MainActivity.this, "avisos", urlApi, null, (long) ultimoAviso));
        }

        /** Saiu do sistema: desliga tudo. */
        @JavascriptInterface
        public void desligarAvisos() {
            runOnUiThread(() -> NetPointService.comando(MainActivity.this, "parar", null, null, 0));
        }

        @JavascriptInterface
        public boolean temFcm() { return tokenFcm != null; }

        @JavascriptInterface
        public String tokenFcm() { return tokenFcm != null ? tokenFcm : ""; }

        @JavascriptInterface
        public boolean rastreando() {
            return NetPointService.rastreando;
        }
    }
}
