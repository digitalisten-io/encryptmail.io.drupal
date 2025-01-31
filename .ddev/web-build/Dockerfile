ARG BASE_IMAGE
FROM $BASE_IMAGE

# Install GnuPG and PHP-GnuPG dependencies
RUN apt-get update && \
  apt-get install -y \
  gnupg \
  libgpgme11-dev \
  php8.3-dev \
  && \
  pecl install gnupg && \
  echo "extension=gnupg.so" > /etc/php/8.3/mods-available/gnupg.ini && \
  phpenmod -v 8.3 gnupg && \
  apt-get clean && \
  rm -rf /var/lib/apt/lists/*
