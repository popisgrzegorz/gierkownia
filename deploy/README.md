# Wdrożenie w LXC (Proxmox) bez Dockera

Docelowa instalacja: kontener Ubuntu 24.04 w LAN. Nginx + PHP 8.3-FPM + Redis +
PostgreSQL + hub Mercure jako usługi systemd, wszystko wstaje samo po restarcie.
Wersja z Dockerem to `compose.yaml` w katalogu głównym.

## Nowy kontener

Na hoście Proxmox (nieuprzywilejowany LXC wystarczy, `nesting` niepotrzebny):

```bash
pct create 202 local:vztmpl/ubuntu-24.04-standard_24.04-2_amd64.tar.zst \
  --hostname gierki --cores 2 --memory 2048 --swap 512 --rootfs local-lvm:12 \
  --unprivileged 1 --net0 name=eth0,bridge=vmbr0,ip=192.168.1.120/24,gw=192.168.1.1 \
  --onboot 1 --start 1 --ssh-public-keys /root/klucz.pub
```

W kontenerze, jako root:

```bash
apt-get update && apt-get install -y git
git clone https://github.com/popisgrzegorz/gierkownia.git /var/www/gierkownia
bash /var/www/gierkownia/deploy/install-lxc.sh
```

Skrypt jest idempotentny: ponowne uruchomienie niczego nie nadpisuje, w tym
`.env.local` z hasłami. Hasła do panelu i do zakładania pokoju:

```bash
grep -E 'ADMIN_PASSWORD|HOST_PASSWORD' /var/www/gierkownia/.env.local
```

## Aktualizacja

```bash
ssh root@192.168.1.120 'bash /var/www/gierkownia/deploy/update.sh'
```

## Czego pilnować

- **Mercure ≥ 1.0 nie zadziała.** Wymaga w tokenie claimu `iss`, a
  `symfony/mercure-bundle` 0.4 go nie wysyła (publikacja pada z „untrusted
  issuer"). Skrypt przypina v0.24.2; `dunglas/mercure:latest` z `compose.yaml`
  ma ten sam problem.
- **Redis w nieuprzywilejowanym LXC** nie startuje bez `PrivateUsers=no`
  (skrypt dopisuje to w drop-inie).
- **PWA wymaga HTTPS.** Po `http://IP` service worker się nie zarejestruje;
  gra działa, ale nie da się jej zainstalować na ekran.
- Hub jest dostępny tylko przez nginx pod `/.well-known/mercure`, port 3010
  nasłuchuje wyłącznie na `127.0.0.1`.
