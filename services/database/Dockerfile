FROM mariadb:11

# envsubst (gettext-base) es necesario para inyectar las variables de entorno (flags, credenciales,
# secretos de progresion) en la plantilla SQL de inicializacion sin commitear valores reales.
RUN apt-get update && apt-get install -y --no-install-recommends gettext-base \
    && rm -rf /var/lib/apt/lists/*

COPY init/01-seed.sql.template /docker-entrypoint-initdb.templates/01-seed.sql.template
COPY entrypoint-wrapper.sh /usr/local/bin/entrypoint-wrapper.sh
RUN chmod +x /usr/local/bin/entrypoint-wrapper.sh

ENTRYPOINT ["entrypoint-wrapper.sh"]
CMD ["mariadbd"]
