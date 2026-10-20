#!/bin/sh
# Times requests to Openverse (and Wikimedia Commons, for comparison) so a
# cuisine-photos run that times out can be traced to the API, the query or
# the network. Prints one line per request: HTTP code, IP, seconds to
# connect / TLS done / first byte / total, and bytes received.
# Run it on the host and in the cli container (`make photo-source-check`).

UA="GuamRestaurants/1.0 (photo-source-check)"
API="https://api.openverse.org/v1/images/"
FILTERS="license=cc0,pdm,by,by-sa&category=photograph&mature=false&filter_dead=false"

check() {
	label="$1"
	shift
	printf '%-34s ' "$label"
	curl -sS -o /dev/null -m 30 -A "$UA" -H 'Accept: application/json' \
		-w 'code=%{http_code} ip=%{remote_ip} connect=%{time_connect} tls=%{time_appconnect} first_byte=%{time_starttransfer} total=%{time_total} bytes=%{size_download}\n' \
		"$@" 2>&1 | tr '\n' ' '
	echo
}

echo "Where: ${1:-this machine}"
check "openverse chamorro (answered before)" "${API}?q=chamorro%20food&${FILTERS}&page_size=20"
check "openverse thai, 1 result" "${API}?q=thai%20food&page_size=1"
check "openverse thai, filters, 20" "${API}?q=thai%20food&${FILTERS}&page_size=20"
check "openverse thai, filters, IPv4 only" -4 "${API}?q=thai%20food&${FILTERS}&page_size=20"
check "openverse thai, HTTP/1.1" --http1.1 "${API}?q=thai%20food&${FILTERS}&page_size=20"
check "openverse thai, HTTP/1.0" --http1.0 "${API}?q=thai%20food&${FILTERS}&page_size=20"
check "wikimedia commons search" "https://commons.wikimedia.org/w/api.php?action=query&list=search&srsearch=thai%20food&srnamespace=6&srlimit=20&format=json"
check "overpass (imports work)" "https://overpass-api.de/api/status"
