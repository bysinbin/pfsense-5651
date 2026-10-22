#!/bin/sh
#
# PROVIDE: posea_5651
# REQUIRE: DAEMON NETWORKING
# BEFORE: LOGIN
# KEYWORD: shutdown
#
# 5651 Yasal Loglama ve Zaman Damgası Servisi
#

. /etc/rc.subr

name="posea_5651"
rcvar="posea_5651_enable"

command="/usr/local/bin/php"
command_args="/usr/local/pkg/posea_5651d.php"
pidfile="/var/run/${name}.pid"

load_rc_config $name
: ${posea_5651_enable:="YES"}

posea_5651_start() {
    echo "Starting Posea 5651 daemon for pfSense..."
    mkdir -p /var/etc/posea_5651
    mkdir -p /var/log/5651/active
    mkdir -p /var/log/5651/archives
    if [ -f "${pidfile}" ]; then
        PID=$(cat "${pidfile}" 2>/dev/null)
        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            echo "Posea 5651 is already running (PID: $PID)."
            return 0
        fi
    fi
    /usr/sbin/daemon -p ${pidfile} -f ${command} ${command_args}
    sleep 1
    if [ -f "${pidfile}" ]; then
        PID=$(cat "${pidfile}" 2>/dev/null)
        echo "Posea 5651 started successfully (PID: $PID)."
    fi
}

posea_5651_stop() {
    echo "Stopping Posea 5651 daemon..."
    if [ -f "${pidfile}" ]; then
        PID=$(cat "${pidfile}" 2>/dev/null)
        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            kill -TERM "$PID" 2>/dev/null
            sleep 1
            if kill -0 "$PID" 2>/dev/null; then
                kill -9 "$PID" 2>/dev/null
            fi
        fi
        rm -f "${pidfile}" 2>/dev/null
    else
        killall -9 php 2>/dev/null | grep posea_5651d || true
    fi
}

posea_5651_status() {
    if [ -f "${pidfile}" ]; then
        PID=$(cat "${pidfile}" 2>/dev/null)
        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            echo "Posea 5651 is running (PID: $PID)."
            return 0
        fi
    fi
    echo "Posea 5651 is not running."
    return 1
}

start_cmd="posea_5651_start"
stop_cmd="posea_5651_stop"
status_cmd="posea_5651_status"
restart_cmd="posea_5651_stop && sleep 1 && posea_5651_start"

run_rc_command "$1"
