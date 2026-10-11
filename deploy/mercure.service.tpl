[Unit]
Description=Mercure hub (Gierkownia)
After=network.target

[Service]
# Caddyfile z paczki hubu musi byc w katalogu roboczym - bez niego `mercure run`
# startuje bez konfiguracji i nic nie nasluchuje.
WorkingDirectory=/opt/mercure
ExecStart=/opt/mercure/mercure run
Environment=SERVER_NAME=:3010
# Tylko petla zwrotna: do huba wchodzi nginx z tej samej maszyny, a publikuje
# aplikacja - port 3010 nie ma sensu w LAN.
Environment="CADDY_SERVER_EXTRA_DIRECTIVES=bind 127.0.0.1"
Environment=MERCURE_PUBLISHER_JWT_KEY=__JWT__
Environment=MERCURE_SUBSCRIBER_JWT_KEY=__JWT__
# Hub widzi tylko nginx z tej samej maszyny, wiec CORS nie jest potrzebny.
Environment="MERCURE_EXTRA_DIRECTIVES=anonymous"
Environment=XDG_DATA_HOME=/var/lib/mercure
Environment=XDG_CONFIG_HOME=/var/lib/mercure
StateDirectory=mercure
DynamicUser=yes
Restart=always

[Install]
WantedBy=multi-user.target
