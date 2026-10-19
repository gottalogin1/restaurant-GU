#!/usr/bin/env sh
# Creates the certificate for local HTTPS (`make https`; README, "Local HTTPS").
#
#   bin/local-https-cert.sh DIR
#
# Writes into DIR (normally .cache/certs, git-ignored):
#   local-ca.crt / local-ca.key   A certificate authority for this machine only. Import
#                                 local-ca.crt into Firefox once. Its name constraints let it
#                                 vouch for localhost and 127.0.0.1 only, so the key cannot be
#                                 used to impersonate any real website.
#   localhost.crt / localhost.key The server certificate Apache uses, signed by that authority.
#
# The authority is kept on re-runs (so Firefox keeps trusting it); the server certificate is
# replaced. Needs only openssl (the Makefile runs it inside the WordPress container).
set -eu

dir=${1:?usage: bin/local-https-cert.sh DIR}
mkdir -p "$dir"
cd "$dir"
umask 077

if [ ! -s local-ca.key ] || [ ! -s local-ca.crt ]; then
	cat > ca.cnf <<'CNF'
[req]
distinguished_name = dn
x509_extensions = ca
prompt = no
[dn]
CN = Guam Restaurants local development CA
O = Guam Restaurants (local only)
[ca]
basicConstraints = critical, CA:true, pathlen:0
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
nameConstraints = critical, permitted;DNS:localhost, permitted;IP:127.0.0.0/255.0.0.0, permitted;IP:0:0:0:0:0:0:0:1/FFFF:FFFF:FFFF:FFFF:FFFF:FFFF:FFFF:FFFF
CNF
	openssl req -x509 -new -newkey rsa:2048 -nodes -sha256 -days 3650 \
		-config ca.cnf -keyout local-ca.key -out local-ca.crt
	rm -f ca.cnf
	echo "Created a new local certificate authority: $dir/local-ca.crt"
fi

cat > server.cnf <<'CNF'
[req]
distinguished_name = dn
prompt = no
[dn]
CN = localhost
[server]
basicConstraints = critical, CA:false
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:localhost, IP:127.0.0.1, IP:::1
authorityKeyIdentifier = keyid
subjectKeyIdentifier = hash
CNF
openssl req -new -newkey rsa:2048 -nodes -sha256 -config server.cnf \
	-keyout localhost.key -out localhost.csr
# 397 days: browsers reject longer-lived server certificates.
openssl x509 -req -in localhost.csr -CA local-ca.crt -CAkey local-ca.key -CAcreateserial \
	-days 397 -sha256 -extfile server.cnf -extensions server -out localhost.crt
rm -f server.cnf localhost.csr local-ca.srl
# Apache reads the server key as www-data; the authority's key stays private.
chmod 644 localhost.crt localhost.key local-ca.crt
openssl verify -CAfile local-ca.crt localhost.crt
