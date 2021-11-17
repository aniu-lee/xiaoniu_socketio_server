FROM ubuntu:16.04
MAINTAINER aniulee@qq.com
RUN apt-get update && apt-get install -y php
CMD ["php","/home/start.php","start"]
EXPOSE 2120
EXPOSE 2121


