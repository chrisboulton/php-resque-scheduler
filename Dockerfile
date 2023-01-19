FROM redis:7.0.7-bullseye

ARG DEBIAN_FRONTEND=noninteractive

# Set work directory
WORKDIR /app

# Add sury php
RUN apt update
RUN apt install -y lsb-release ca-certificates apt-transport-https software-properties-common gnupg2 curl
RUN echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/sury-php.list
RUN curl -fsSL  https://packages.sury.org/php/apt.gpg| gpg --dearmor -o /etc/apt/trusted.gpg.d/sury-keyring.gpg

# Install php
RUN apt update
RUN apt install php8.1 php8.1-dom php8.1-mbstring php8.1-curl -y

# Install tools
RUN apt install zip unzip php-zip -y

# Install composer and dependencies
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
