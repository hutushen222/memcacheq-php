Install MemcacheQ
=================

[MemcacheQ](http://memcachedb.org/memcacheq/INSTALL.html) is a [MemcacheDB](http://memcachedb.org/) variant that provides a simple message queue service.

Dependencies
------------

Before deploying MemcacheQ, make sure that following packages have been installed:

Berkeley DB 4.7 or later
------------------------

Download from http://www.oracle.com/database/berkeley-db/db/index.html

How to install BerkekeyDB:

	$tar xvzf db-4.7.25.tar.gz
	$cd db-4.7.25/
	$cd build_unix/
	$../dist/configure
	$make
	$sudo make install

libevent 1.4.x or later
-----------------------

Download from http://monkey.org/~provos/libevent/

How to install libevent:

	$tar xvzf libevent-1.4.x-stable.tar.gz
	$cd libevent-1.4.x-stable
	$./configure
	$make
	$sudo make install

On a linux, load .so file by add two line in /etc/ld.so.conf:

	/usr/local/lib
	/usr/local/BerkeleyDB.4.7/lib

Then, run 'ldconfig'.


Building MemcacheQ
------------------

On a \*nix, just following:

	$tar xvzf memcacheq-0.2.x.tar.gz
	$cd memcacheq-0.2.x
	$./configure --enable-threads
	$make
	$sudo make install

Start the daemon

For example:

	memcacheq -d -r -H /data1/memcacheq -N -R -v -L 1024 -B 1024 > /data1/mq_error.log 2>&1

Notice: Because MemcacheQ is using fixed-length storage, so you should use '-B' option to specify the max length of your message. Default is 1024 bytes. Any message that shorter than the length you specified will be padded with '0x20', the space character. A message includes following bytes:

\<your queue name bytes\> + \<message metadata(9 ~ 20+ bytes)\> + \<your message body bytes\>

use "-h" option to see more configures.

Have fun :)

