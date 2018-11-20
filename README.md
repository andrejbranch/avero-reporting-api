# avero-reporting-api
Rreporting API on top of point of sale data extracted from Avero customer restaurants

# Running the api
In the spirt of microservices, I've setup a docker container that will setup the api for you with just the following command. Of course you'll need to have docker installed https://www.docker.com/products/docker-desktop

```docker run -d -p 8080:80 andrejbranch/avero-reporting-api```

If port 8080 is taken on your system you can exchange 8080 for any open port of your choosing.

The docker image is hosted publicly on docker cloud here https://cloud.docker.com/repository/docker/andrejbranch/avero-reporting-api/general

After the container is started you will be able to make get request calls to localhost:8080/reporting

To avoid writing a bunch of parameter validation, the reporting api will return results with the following defaults:
- reportType: EGS
- businessId: e0b6683d-5efc-4b7a-836d-f3a3fe16ebae
- start: - 24 hours
- end: now
- timeInterval: day
- limit: 100
- offset: 0

All source code can be found in this repo in the following paths
src/src/Command/*
src/src/Controller/*
src/src/Service/*

# Build the container from scratch
If your curious you can build the container from scratch with the following command
```docker build -t avero-reporting-api --build-arg avero_api_auth=$averoAuthToken /path/to/this/repo```
Replace $averoAuthToken with a working api token 

# Generate hourly report data from scratch
The report hourly base data is generated in the docker build itself @see DockerFile. It takes some time, but if you want to run the generate report command to see it in action you can do the following:
```
# login to the container
docker run -it -p 8080:80 andrejbranch/avero-reporting-api /bin/bash
# generate the hourly report data
/app/src/bin/console avero:generate-report $apiAuthToken
``` 
