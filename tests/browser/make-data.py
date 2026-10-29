# Generate full-size, council-shaped test data for the map page, dated relative to today.
# Usage: python3 make-data.py <output-dir>   (also writes expected.json for map.test.js)
import json, random, datetime, sys, os
random.seed(7)
out = sys.argv[1]
today = datetime.date.today()
streets = ["Clumber Road East", "Lenton Road", "Cavendish Crescent South", "Park Valley", "Newcastle Drive",
           "Tunnel Road", "North Road", "Western Terrace", "Park Terrace", "Hamilton Drive", "Duke William Mount",
           "Huntingdon Drive", "Lincoln Circus", "Rope Walk", "Cavendish Road East", "Ogle Drive"]
cats = [("Pending Consideration", None, "pending"), ("Granted conditional permission", "Grant conditional permission", "granted"),
        ("No objection", "No objection", "granted"), ("Approved", "Approve", "granted"), ("Refused consent", "Refuse consent", "refused"),
        ("Refused permission", "Refuse permission", "refused"), ("Application withdrawn", "Application withdrawn", "withdrawn"),
        ("TPO made", "TPO made (PWC notifications only)", "other")]
weights = [3, 30, 30, 15, 5, 4, 5, 1]
types = [("PWC", "Works to trees in a conservation area", True), ("PFUL3", "Full planning application", False),
         ("LLIS1", "Listed building consent", False), ("PTPO", "Works to protected trees (TPO)", True), ("PCLE", "Lawful development certificate (existing)", False)]
lon0, lon1, lat0, lat1 = -1.1680, -1.1565, 52.9480, 52.9550
houses = [(random.uniform(lon0, lon1), random.uniform(lat0, lat1), random.choice(streets), random.randint(1, 60)) for _ in range(900)]
feats = []
def add(i, received, cat, house, proposal=None, first_seen=None, typ=None):
    lon, lat, street, num = house
    status, decision, category = cat
    code, label, trees = typ or random.choice(types)
    ref = f"{received.year % 100:02d}/{i:05d}/{code}"
    decided = None if category == "pending" else received + datetime.timedelta(days=random.randint(20, 90))
    feats.append({"type": "Feature", "geometry": {"type": "Point", "coordinates": [round(lon, 6), round(lat, 6)]},
      "properties": {"ref": ref, "slug": ref.replace("/", "-"), "type": code, "type_label": label, "trees": trees,
        "address": f"{num} {street}, Nottingham, Nottingham City, NG7 1BD",
        "proposal": proposal or random.choice(["T1 Sycamore: reduce crown by 2m to previous pruning points.",
            "Erection of single storey rear extension and replacement of timber sash windows like for like.",
            "Repairs to boundary wall and replacement of railings to match the original Victorian design.",
            "Change of use from dwelling (C3) to house in multiple occupation (C4) for 6 persons."]),
        "received": received.isoformat(), "valid": received.isoformat(), "status": status,
        "decision": None if category == "pending" else decision, "decision_date": decided.isoformat() if decided and decided <= today else None,
        "category": category, "first_seen": first_seen, "url": f"https://publicaccess.nottinghamcity.gov.uk/online-applications/applicationDetails.do?activeTab=summary&keyVal=K{i:011d}"}})
i = 0
for n in range(5700):
    days = int(random.betavariate(1.2, 2.5) * 365 * 93)       # back to 1933, skewed recent
    received = today - datetime.timedelta(days=days)
    cat = random.choices(cats, weights)[0]
    if days < 120 and random.random() < 0.6: cat = cats[0]
    elif days > 400 and cat[2] == "pending": cat = cats[1]
    add(i, received, cat, random.choice(houses)); i += 1
for n in range(70):  # ensure a realistic last-12-months volume
    received = today - datetime.timedelta(days=random.randint(0, 360))
    add(i, received, cats[0] if received > today - datetime.timedelta(days=90) else random.choices(cats, weights)[0], random.choice(houses)); i += 1
# 12 applications at one house, to exercise spiderfying
big = (-1.16014, 52.95141, "Clumber Road East", 1)
for n in range(12): add(i, today - datetime.timedelta(days=30 * n + 5), random.choices(cats, weights)[0], big); i += 1
# the real latest one, new this week, plus a hostile proposal
add(i, today - datetime.timedelta(days=11), cats[0], big, "T1 - Cherry: Reduce overhanging branches to 1 Clumber Road East and adjacent property to the fence/boundary line to appropriate growth points", (today - datetime.timedelta(days=1)).isoformat(), types[0]); feats[-1]["properties"].update(ref="26/01848/PWC", slug="26-01848-PWC"); i += 1
add(i, today - datetime.timedelta(days=7), cats[0], houses[5], '<img src=x onerror="window.__xss=1"> Proposal with <b>markup</b> & "quotes"', (today - datetime.timedelta(days=2)).isoformat(), types[1]); feats[-1]["properties"].update(ref="26/01900/PFUL3", slug="26-01900-PFUL3"); i += 1
add(i, datetime.date(1999, 3, 2), cats[1], houses[9], "Long proposal " + "x" * 290, None, types[1]); feats[-1]["properties"].update(ref="99/00123/PFUL3", slug="99-00123-PFUL3"); i += 1
# One application just outside the conservation area (west of it), in the 50 m margin.
add(i, today - datetime.timedelta(days=9), cats[0], (-1.1699, 52.9505, "Derby Road", 224), "7 bedroom Sui Generis House in Multiple Occupation", (today - datetime.timedelta(days=2)).isoformat(), types[1]); feats[-1]["properties"].update(ref="26/01870/PFUL3", slug="26-01870-PFUL3"); i += 1

ring = [[-1.1693, 52.9476], [-1.1611, 52.9474], [-1.1559, 52.9496], [-1.1561, 52.9538], [-1.1600, 52.9555], [-1.1662, 52.9551], [-1.1693, 52.9520], [-1.1693, 52.9476]]
def inside(x, y, poly):
    c = False
    for k in range(len(poly) - 1):
        (x1, y1), (x2, y2) = poly[k], poly[k + 1]
        if (y1 > y) != (y2 > y) and x < (x2 - x1) * (y - y1) / (y2 - y1) + x1:
            c = not c
    return c
for f in feats:
    lon, lat = f["geometry"]["coordinates"]
    f["properties"]["inside"] = inside(lon, lat, ring)
# The watch area: the conservation area widened by roughly 50 m (generous, as a box around it).
watch = [[-1.1706, 52.9467], [-1.1546, 52.9467], [-1.1546, 52.9562], [-1.1706, 52.9562], [-1.1706, 52.9467]]

os.makedirs(out, exist_ok=True)
json.dump({"type": "FeatureCollection", "features": feats}, open(f"{out}/applications.geojson", "w"))
json.dump({"synced_at": "2026-10-27 05:17:03Z", "applications": len(feats), "unplaced": 0,
           "outside": sum(1 for f in feats if not f["properties"]["inside"]), "buffer_metres": 50}, open(f"{out}/meta.json", "w"))
json.dump({"type": "FeatureCollection", "features": [{"type": "Feature", "properties": {"buffer_metres": 50}, "geometry": {"type": "Polygon", "coordinates": [watch]}}]}, open(f"{out}/watch-area.geojson", "w"))
json.dump({"type": "FeatureCollection", "features": [{"type": "Feature", "properties": {"NAME": "The Park"}, "geometry": {"type": "Polygon", "coordinates": [ring]}}]}, open(f"{out}/park-boundary.geojson", "w"))
def years_ago(n):
    try: return today.replace(year=today.year - n)
    except ValueError: return today.replace(year=today.year - n, day=28)
cut = years_ago(1).isoformat()
last12 = sum(1 for f in feats if f["properties"]["received"] >= cut)
nearby12 = sum(1 for f in feats if f["properties"]["received"] >= cut and not f["properties"]["inside"])
json.dump({"total": len(feats), "last12": last12, "nearby12": nearby12, "synced": "27 October 2026 at 05:17"}, open(f"{out}/expected.json", "w"))
print(len(feats), "features;", last12, "in the last 12 months, of which", nearby12, "in the margin")
