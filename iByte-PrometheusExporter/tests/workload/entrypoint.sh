#!/bin/sh
# Synthetic resource workload used only by the disposable real-Wings fixture.
cd /home/container
dd if=/dev/zero of=known-size.bin bs=1048576 count=1 2>/dev/null
exec awk 'BEGIN { data=sprintf("%16777216s", "x"); print "READY"; fflush(); while (1) { x++; if (x > 1000000) x=0; } }'
