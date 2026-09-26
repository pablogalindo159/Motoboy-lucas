package br.com.netpoint.rotas;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;

import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * Recebe os avisos pelo Firebase (chegam na hora, mesmo com o celular bloqueado).
 * Com o app fechado o próprio Android mostra a notificação; com o app aberto, mostramos aqui.
 */
public class NetPointFcmService extends FirebaseMessagingService {

    @Override
    public void onNewToken(String token) {
        getSharedPreferences("netpoint", MODE_PRIVATE).edit().putString("fcm_token", token).apply();
        // o token novo é registrado no servidor na próxima vez que a tela do sistema abrir
    }

    @Override
    public void onMessageReceived(RemoteMessage msg) {
        Map<String, String> d = msg.getData();
        String titulo = d.containsKey("titulo") ? d.get("titulo") : (msg.getNotification() != null ? msg.getNotification().getTitle() : "NetPoint Rotas");
        String texto = d.containsKey("texto") ? d.get("texto") : (msg.getNotification() != null ? msg.getNotification().getBody() : "");
        String link = d.get("link");
        int id;
        try { id = Integer.parseInt(d.get("id")); } catch (Exception e) { id = (int) (System.currentTimeMillis() % 100000); }

        NetPointService.criarCanais(this);
        Intent abrir = new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        if (link != null && !link.isEmpty()) abrir.putExtra("link", link);
        PendingIntent pi = PendingIntent.getActivity(this, id, abrir, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, "avisos") : new Notification.Builder(this);
        b.setContentTitle(titulo).setContentText(texto)
                .setStyle(new Notification.BigTextStyle().bigText(texto))
                .setSmallIcon(android.R.drawable.ic_dialog_info)
                .setAutoCancel(true)
                .setContentIntent(pi);
        if (Build.VERSION.SDK_INT < 26) b.setPriority(Notification.PRIORITY_HIGH).setDefaults(Notification.DEFAULT_ALL);
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return;
        ((NotificationManager) getSystemService(NOTIFICATION_SERVICE)).notify(1000 + id, b.build());
    }
}
