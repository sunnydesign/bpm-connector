FROM ubuntu
LABEL MAINTAINER am@kubia.com

ENV TZ=Asia/Singapore

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    &&  apt-get -yqq update && apt-get -yqq upgrade && apt-get -yqq install \
        apt-utils \
        php7.2-cli \
        php7.2-bcmath \
        php-mbstring \
        php-amqp \
        htop \
        mc \
        composer

COPY ./ /opt/ms

#################################
# Supervisor & log
#################################

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN mkdir -p /var/log/supervisor \
    && apt-get install -y supervisor

#################################
# Composer
#################################

RUN useradd composer -b /home/composer \
    && mkdir /home/composer \
    && chown composer:composer /home/composer \
    && echo "alias composer='composer'" >> /home/composer/.bashrc \
    && cd /opt/ms \
    && chown -R composer:composer /opt/ms \
    && su composer -c 'composer install' \
    && chown -R www-data:www-data /opt/ms

WORKDIR /opt/ms

ENTRYPOINT /usr/bin/supervisord