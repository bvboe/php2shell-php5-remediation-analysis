import sys, urllib.request, uuid
url, n = sys.argv[1], int(sys.argv[2])
b = "----lab" + uuid.uuid4().hex
parts = []
for i in range(n):
    parts.append(("--%s\r\nContent-Disposition: form-data; name=\"f%d\"\r\n\r\nv%d\r\n" % (b, i, i)).encode())
parts.append(("--%s--\r\n" % b).encode())
body = b"".join(parts)
req = urllib.request.Request(url, data=body, method="POST")
req.add_header("Content-Type", "multipart/form-data; boundary=" + b)
try:
    with urllib.request.urlopen(req, timeout=30) as r:
        print("   HTTP %s | %s" % (r.status, r.read().decode().strip().replace("\n", " | ")))
except Exception as e:
    print("   ERROR:", e)
