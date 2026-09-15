FROM appwrite/appwrite@sha256:27ca3f70d06fb0f4751e93cba03c49f96562236986f9b688185ee1c0e1f7e9aa

LABEL org.opencontainers.image.source="https://github.com/Revento-sp-z-o-o/appwrite"
LABEL app.revento.upstream.version="2.0.0"
LABEL app.revento.qualification="candidate"

COPY revento /tmp/revento
RUN apk add --no-cache --virtual .revento-build patch=2.8-r0 \
    && REVENTO_DATABASE_SOURCE=/usr/src/code/vendor/utopia-php/database/src/Database/Database.php \
       REVENTO_APPWRITE_SOURCE=/usr/src/code php /tmp/revento/tests/patches.php \
    && php /tmp/revento/apply.php /usr/src/code \
    && php -l /usr/src/code/app/http.php \
    && php -l /usr/src/code/vendor/utopia-php/database/src/Database/Database.php \
    && mkdir -p /usr/local/share/revento \
    && cp /tmp/revento/manifest.json /usr/local/share/revento/manifest.json \
    && rm -r /tmp/revento \
    && apk del .revento-build
