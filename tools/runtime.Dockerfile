FROM scratch
COPY . /
ENV PHPRC=/app/php.ini PHP_INI_SCAN_DIR=/app/php.d
ENTRYPOINT ["/app/type-app"]
