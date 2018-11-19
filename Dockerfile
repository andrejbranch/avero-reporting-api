FROM andrejbranch/php7-apache-mongo

ARG avero_api_auth

# Set the working directory to /app
WORKDIR /app

# Copy the current directory contents into the container at /app
ADD . /app

# setup apache conf

RUN cp httpd.conf /usr/local/apache2/conf/httpd.conf

RUN cp httpd-vhosts.conf /usr/local/apache2/conf/extra/httpd-vhosts.conf

# syncronize avero data and generate report

RUN /app/mongodb-linux-x86_64-amazon-4.0.4/bin/mongod --logpath /var/log/mongo --dbpath /app/mongo_data/ --fork && /app/src/bin/console avero:generate-report $avero_api_auth

RUN chown -R daemon /app/src

EXPOSE 80

# execute default entry point

CMD sh entry.sh
