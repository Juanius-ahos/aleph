<?php
/**
 * Aleph — Print Quotation Engine Installer
 * Run ONCE from the browser while logged in as admin, then DELETE this file.
 * Creates all pq_* tables, seeds real Aleph catalog + company settings, registers module/perms.
 * Idempotent: tables use IF NOT EXISTS, columns are added if missing, seeds only run when a table is empty.
 */

require_once __DIR__ . '/config.php';

if (!isLoggedIn() || currentUserRole() !== 'admin') {
    http_response_code(403);
    die('You must be logged in as an administrator to run the installer. <a href="login.php">Login</a>');
}

$db = getDB();
$log = [];
function step(&$log, $msg, $ok = true) { $log[] = ['ok' => $ok, 'msg' => $msg]; }
function columnExists($db, $table, $col) {
    $r = dbFetch($db, "SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [$table, $col]);
    return $r && $r['c'] > 0;
}
function pq_product_list($sizeMap, $paperMap, $finMap) {
    // Format: [name, category, desc, sizeName, paperName, method, sides, cf, cb, qty, flatW, flatH, depth, pages, [finishingNames]]
    $o = 'offset'; $d = 'digital';
    $products = [
        // ──────────── Boxes (17.9% of real data — Diecut + Gluing is universal) ────────────
        ['Box (Diecut + Gluing)', 'Boxes', 'Standard folding box, Invercoat 350.', 'Square 15', 'Invercoat 350', $o, 1, 4, 0, 500, null, null, 8.0, 1, ['Diecut', 'Gluing']],
        ['Box + Lamination', 'Boxes', 'Laminated folding box.', 'Square 15', 'Invercoat 350', $o, 2, 4, 4, 500, null, null, 8.0, 1, ['Lamination Matt', 'Diecut', 'Gluing']],
        ['Box + Matt Lamination + Embossing', 'Boxes', 'Premium box with embossed logo.', 'Square 15', 'Invercoat 350', $o, 2, 4, 4, 500, null, null, 8.0, 1, ['Lamination Matt', 'Embossing', 'Diecut', 'Gluing']],
        ['Kraft Box', 'Boxes', 'Clay-coated kraft folding box.', 'Square 15', 'CKB 350', $o, 1, 4, 0, 500, null, null, 8.0, 1, ['Diecut', 'Gluing']],
        ['Kraft Box + Lamination', 'Boxes', 'Laminated kraft box.', 'Square 15', 'CKB 350', $o, 2, 4, 0, 500, null, null, 8.0, 1, ['Lamination Matt', 'Diecut', 'Gluing']],
        ['Rigid Hard Box (Top + Bottom)', 'Boxes', 'Litho-laminated rigid box on greyboard.', 'Square 15', 'Coated 170 on Greyboard', $o, 2, 4, 0, 200, null, null, 8.0, 1, ['Diecut', 'Hardbox Operation', 'Covering']],
        ['Magnet Closure Box', 'Boxes', 'Rigid box with magnetic closure.', 'Square 15', 'Invercoat 350', $o, 2, 4, 4, 100, null, null, 8.0, 1, ['Lamination Matt', 'Diecut', 'Hardbox Operation', 'Magnet Closure', 'Covering']],
        ['Window Box', 'Boxes', 'Box with die-cut window.', 'Square 15', 'Invercoat 350', $o, 2, 4, 4, 500, null, null, 8.0, 1, ['Diecut', 'Window', 'Gluing']],
        ['Stand / Display Box', 'Boxes', 'Counter display / stand box.', 'A5', 'Invercoat 350', $o, 1, 4, 0, 500, null, null, 15.0, 1, ['Lamination Matt', 'Diecut', 'Gluing']],
        ['Drawer Box', 'Boxes', 'Sliding drawer box with sleeve.', 'Square 15', 'Invercoat 300', $o, 2, 4, 4, 200, null, null, 10.0, 1, ['Lamination Matt', 'Diecut', 'Gluing']],
        ['Perfume Box', 'Boxes', 'Luxury perfume packaging.', 'Square 15', 'Coated Matt 350', $o, 2, 4, 4, 100, null, null, 10.0, 1, ['Lamination Matt', 'Hot-foil', 'Diecut', 'Hardbox Operation', 'Covering']],
        ['Wine Box', 'Boxes', 'Single bottle wine box.', 'Square 15', 'Invercoat 300', $o, 1, 4, 0, 200, null, null, 10.0, 1, ['Diecut', 'Scoring', 'Gluing']],
        ['Cosmetic Box', 'Boxes', 'Foldable cosmetic carton.', 'Square 15', 'Coated 350', $o, 2, 4, 4, 500, null, null, 8.0, 1, ['Lamination Matt', 'Diecut', 'Gluing']],
        ['Pyramid Box', 'Boxes', 'Pyramid-shaped gift box.', 'Square 15', 'Invercoat 350', $o, 2, 4, 4, 200, null, null, 8.0, 1, ['Lamination Matt', 'Diecut', 'Gluing']],
        ['Tissue Box', 'Boxes', 'Luxury tissue box cover.', 'Square 15', 'Coated 350', $o, 2, 4, 4, 500, null, null, 12.0, 1, ['Lamination Matt', 'Diecut', 'Gluing']],
        ['Gift Box (Lid + Base)', 'Boxes', 'Two-piece gift box.', 'Square 15', 'Invercoat 350', $o, 2, 4, 4, 200, null, null, 10.0, 1, ['Lamination Glossy', 'Diecut', 'Hardbox Operation', 'Covering']],
        ['Pizza / Food Box', 'Boxes', 'Food-grade flat-pack box.', 'A4', 'Coated 300', $o, 1, 4, 0, 1000, null, null, 5.0, 1, ['Diecut', 'Gluing']],
        ['Mailing Box', 'Boxes', 'Corrugated mailing/shipping box.', 'A4', 'Coated 350', $o, 1, 4, 0, 500, null, null, 8.0, 1, ['Diecut', 'Scoring', 'Gluing']],
        ['Soft Box (with insert)', 'Boxes', 'Folding soft box with insert.', 'Square 15', 'Invercoat 350', $o, 2, 4, 4, 500, null, null, 10.0, 1, ['Diecut', 'Gluing']],

        // ──────────── Bags (10.2% — always: Diecut + Lamination + Handles + Gluing + Bag Fabrication) ────────────
        ['Shopping Bag', 'Bags', 'Standard laminated shopping bag. Price using flat (unfolded) dimensions.', 'A4', 'Invercoat 220', $o, 1, 4, 0, 500, 40.0, 35.0, 30.0, 1, ['Lamination Matt', 'Diecut', 'Rope Handles', 'Gluing', 'Bag Fabrication']],
        ['Shopping Bag + Ribbon Handles', 'Bags', 'Premium bag with grosgrain ribbon handles.', 'A4', 'Invercoat 220', $o, 1, 4, 0, 500, 40.0, 35.0, 30.0, 1, ['Lamination Matt', 'Diecut', 'Ribbon Handles', 'Gluing', 'Bag Fabrication']],
        ['Shopping Bag + Hot-Foil', 'Bags', 'Premium bag with foil-stamped logo.', 'A4', 'Invercoat 220', $o, 1, 4, 0, 500, 40.0, 35.0, 30.0, 1, ['Lamination Matt', 'Hot-foil', 'Diecut', 'Rope Handles', 'Gluing', 'Bag Fabrication']],
        ['Small Gift Bag', 'Bags', 'Small laminated gift bag with handles.', 'A5', 'Invercoat 220', $o, 1, 4, 0, 500, 30.0, 28.0, 20.0, 1, ['Lamination Matt', 'Diecut', 'Rope Handles', 'Gluing', 'Bag Fabrication']],
        ['Medium Gift Bag', 'Bags', 'Medium laminated gift bag with rope handles.', 'A5', 'Invercoat 220', $o, 1, 4, 0, 500, 38.0, 30.0, 25.0, 1, ['Lamination Matt', 'Diecut', 'Rope Handles', 'Gluing', 'Bag Fabrication']],
        ['Large Shopping Bag', 'Bags', 'Large laminated shopping bag with ribbon handles.', 'A3', 'Invercoat 250', $o, 1, 4, 0, 500, 55.0, 45.0, 35.0, 1, ['Lamination Matt', 'Diecut', 'Ribbon Handles', 'Gluing', 'Bag Fabrication']],
        ['Luxury Ribbon Bag', 'Bags', 'Premium bag with velvet lamination and ribbon.', 'A4', 'Invercoat 250', $o, 1, 4, 0, 300, 45.0, 38.0, 30.0, 1, ['Lamination Velvet', 'Diecut', 'Ribbon Handles', 'Gluing', 'Bag Fabrication']],
        ['Paper Sack', 'Bags', 'Plain kraft paper sack (no handles).', 'A4', 'CKB 350', $o, 1, 4, 0, 1000, 35.0, 25.0, 15.0, 1, ['Diecut', 'Gluing', 'Bag Fabrication']],

        // ──────────── Books (14.3% — the most complex products) ────────────
        ['Booklet (Saddle Stitch)', 'Books', 'Saddle-stitched booklet.', 'A5', 'Coated 150', $o, 2, 4, 4, 500, null, null, null, 32, ['Saddle Stitching', 'Cut-to-size']],
        ['Booklet + Cover Lamination', 'Books', 'Saddle-stitched booklet with laminated cover.', 'A5', 'Coated 150', $o, 2, 4, 4, 500, null, null, null, 32, ['Lamination Matt', 'Saddle Stitching', 'Cut-to-size']],
        ['Perfect Bound Book', 'Books', 'Perfect bound softcover book.', 'A5', 'Coated 150', $o, 2, 4, 4, 300, null, null, null, 96, ['Lamination Matt', 'Perfect Binding', 'Cut-to-size']],
        ['Case-Bound Book (Hardcover)', 'Books', 'Sewn case-bound hardcover book.', 'A4', 'Coated 170', $o, 2, 4, 0, 100, null, null, 2.5, 120, ['Lamination on Cover', 'Sewing', 'Hardbox Operation', 'Covering']],
        ['Hardcover Book (Board + Wrap)', 'Books', 'Full rigid hardcover, sewn or perfect bound.', 'A5', 'Greyboard (hardbox)', $o, 2, 4, 0, 100, null, null, 2.0, 120, ['Hardbox Operation', 'Covering', 'Perfect Binding']],
        ['Album Book', 'Books', 'Hardcover photo album.', 'A4', 'Coated 200', $o, 2, 4, 4, 100, null, null, 2.0, 60, ['Lamination on Cover', 'Sewing', 'Hardbox Operation', 'Covering']],
        ['PUR Bound Book', 'Books', 'PUR-bound softcover book.', 'A5', 'Coated 150', $o, 2, 4, 4, 300, null, null, null, 80, ['Lamination Matt', 'PUR Binding', 'Cut-to-size']],
        ['Wire-O Notebook', 'Books', 'Wire-O bound notebook.', 'A5', 'Coated 150', $o, 2, 4, 4, 300, null, null, null, 80, ['Lamination Matt', 'Wire-O Binding', 'Cut-to-size']],
        ['Catalog (Perfect Bound)', 'Books', 'Product catalog, perfect bound.', 'A4', 'Coated 170', $o, 2, 4, 4, 500, null, null, null, 64, ['Lamination Matt', 'Perfect Binding', 'Cut-to-size']],
        ['Magazine', 'Books', 'Staple-bound magazine.', 'A4', 'Coated 135', $o, 2, 4, 4, 1000, null, null, null, 48, ['Saddle Stitching', 'Cut-to-size']],
        ['Children\'s Book', 'Books', 'Full-color children\'s book.', 'A4', 'Coated 170', $o, 2, 4, 4, 1000, null, null, null, 32, ['Lamination Glossy', 'Saddle Stitching', 'Cut-to-size']],
        ['Guide Book', 'Books', 'Perfect bound guide / manual.', 'A5', 'Coated 170', $o, 2, 4, 4, 500, null, null, null, 48, ['Lamination Matt', 'Perfect Binding', 'Cut-to-size']],
        ['Recipe Book', 'Books', 'Laminated recipe book, perfect bound.', 'A5', 'Coated 200', $o, 2, 4, 4, 300, null, null, null, 120, ['Lamination Matt', 'Perfect Binding']],
        ['Sketchbook', 'Books', 'Uncoated sketchbook, saddle-stitched.', 'A4', 'Wood-Free 100', $o, 1, 1, 0, 500, null, null, null, 48, ['Saddle Stitching', 'Cut-to-size']],
        ['Wire-O Calendar Book', 'Books', 'Wire-O bound wall calendar.', 'A4', 'Coated 170', $o, 2, 4, 4, 500, null, null, null, 12, ['Wire-O Binding', 'Cut-to-size']],
        ['Bloc-Note', 'Books', 'Tape-bound notepad / bloc-note.', 'A5', 'Wood-Free 80', $o, 1, 1, 0, 200, null, null, null, 50, ['Pad Gluing', 'Backboard']],
        ['Booklet (Digital)', 'Books', 'Small-run digital booklet.', 'A5', 'Coated 150', $d, 2, 4, 4, 100, null, null, null, 24, ['Saddle Stitching', 'Cut-to-size']],

        // ──────────── Commercial (leaflets = #1 product by volume) ────────────
        ['Leaflet (Folded)', 'Commercial', 'Pharma leaflet / insert, folded.', 'A5', 'Wood-Free 45', $o, 2, 1, 1, 5000, null, null, null, 1, ['Folding']],
        ['Leaflet 1/0 (B&W)', 'Commercial', 'Black & white leaflet, single-sided.', 'A5', 'Wood-Free 45', $o, 1, 1, 0, 5000, null, null, null, 1, ['Folding']],
        ['Leaflet 4/0 (Full Colour)', 'Commercial', 'Full-colour single-sided leaflet.', 'A5', 'Wood-Free 45', $o, 1, 4, 0, 5000, null, null, null, 1, ['Folding']],
        ['Flyer A5', 'Commercial', 'Full-colour A5 flyer.', 'A5', 'Coated 200', $o, 2, 4, 4, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Flyer A4', 'Commercial', 'Full-colour A4 flyer.', 'A4', 'Coated 150', $o, 2, 4, 4, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Flyer A6', 'Commercial', 'Small A6 flyer.', 'A6', 'Coated 200', $o, 2, 4, 4, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Brochure Trifold', 'Commercial', 'Tri-fold brochure A4 to DL.', 'A4', 'Coated 150', $o, 2, 4, 4, 500, null, null, null, 1, ['Scoring', 'Folding']],
        ['Brochure Bifold (A4)', 'Commercial', 'Half-fold A4 brochure.', 'A4', 'Coated 170', $o, 2, 4, 4, 500, null, null, null, 1, ['Scoring', 'Folding']],
        ['Brochure (Saddle-Stitched)', 'Commercial', 'Multi-page saddle-stitched brochure.', 'A4', 'Coated 150', $o, 2, 4, 4, 500, null, null, null, 16, ['Saddle Stitching', 'Cut-to-size']],
        ['Poster A3', 'Commercial', 'Full-colour A3 poster.', 'A3', 'Coated 150', $o, 1, 4, 0, 200, null, null, null, 1, ['Cut-to-size']],
        ['Poster A2', 'Commercial', 'Full-colour A2 poster.', 'A2', 'Coated 135', $o, 1, 4, 0, 100, null, null, null, 1, ['Cut-to-size']],
        ['Menu (A4)', 'Commercial', 'Restaurant menu, laminated.', 'A4', 'Coated 350', $o, 2, 4, 4, 100, null, null, null, 1, ['Lamination Matt', 'Cut-to-size']],
        ['Program / Playbill', 'Commercial', 'Event program, saddle-stitched.', 'A5', 'Coated 150', $o, 2, 4, 4, 500, null, null, null, 16, ['Saddle Stitching', 'Cut-to-size']],
        ['Desk Calendar (Wire-O)', 'Commercial', 'Wire-O desk calendar with stand.', 'Desk Calendar 21.5x15.5', 'Coated 350', $o, 2, 4, 4, 100, null, null, null, 1, ['Scoring', 'Assembling', 'Wire-O Binding']],
        ['Price List / Catalog Sheet', 'Commercial', 'Single or multi-page price list.', 'A4', 'Coated 150', $o, 2, 4, 4, 500, null, null, null, 4, ['Cut-to-size']],
        ['Roll-up Banner', 'Commercial', 'Retractable roll-up banner.', 'Roll-up 85x200', 'Coated 150', $d, 1, 4, 0, 1, null, null, null, 1, ['Cut-to-size']],
        ['Banner (Large Format)', 'Commercial', 'Large format banner / signage.', 'A1', 'White Vinyl', $d, 1, 4, 0, 10, null, null, null, 1, ['Cut-to-size']],
        ['Presentation Folder', 'Commercial', 'Printed folder with pocket.', 'A4', 'Coated 300', $o, 2, 4, 4, 200, null, null, null, 1, ['Diecut', 'Scoring', 'Gluing', 'Gluing Pocket']],
        ['Folder with Pocket', 'Commercial', 'A4 folder with glued pocket.', 'A4', 'Coated 300', $o, 2, 4, 4, 200, null, null, null, 1, ['Diecut', 'Scoring', 'Gluing', 'Gluing Pocket']],
        ['Table Talker', 'Commercial', 'Folded table tent / talker.', 'A4', 'Coated 350', $o, 2, 4, 4, 200, null, null, null, 1, ['Scoring', 'Cut-to-size']],
        ['Window Sticker', 'Commercial', 'Shop window sticker / decal.', 'A4', 'White adhesive', $d, 1, 4, 0, 500, null, null, null, 1, ['Cut-to-size']],
        ['Certificate', 'Commercial', 'Printed certificate / diploma.', 'A4', 'Cotton', $o, 2, 4, 4, 100, null, null, null, 1, ['Cut-to-size']],
        ['Wrapping Paper', 'Commercial', 'Custom printed wrapping paper.', 'A3', 'Coated 150', $o, 1, 4, 0, 200, null, null, null, 1, ['Cut-to-size']],
        ['Door Hanger', 'Commercial', 'Die-cut door hanger.', 'A4', 'Coated 300', $o, 2, 4, 4, 500, null, null, null, 1, ['Diecut', 'Cut-to-size']],
        ['Wobbler', 'Commercial', 'Adhesive wobbler / shelf-talker.', 'A5', 'Coated 350', $d, 2, 4, 4, 500, null, null, null, 1, ['Diecut', 'Cut-to-size']],

        // ──────────── Labels (7.5% — sheet vs roll distinction is key) ────────────
        ['Stickers (Sheet)', 'Labels', 'Die-cut adhesive labels on sheets.', 'Label 5x8', 'White adhesive', $d, 1, 4, 0, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Stickers (Roll — Slitting)', 'Labels', 'Product labels, slitted to size.', 'Label 5x8', 'White adhesive', $d, 1, 4, 0, 2000, null, null, null, 1, ['Diecut (Slitting)', 'Slitting']],
        ['Round Stickers', 'Labels', 'Round die-cut stickers.', 'Sticker Ø4', 'White adhesive', $d, 1, 4, 0, 500, null, null, null, 1, ['Cut-to-size']],
        ['Round Stickers (Roll)', 'Labels', 'Round stickers on roll.', 'Sticker Ø4', 'White adhesive', $d, 1, 4, 0, 2000, null, null, null, 1, ['Diecut (Slitting)', 'Slitting']],
        ['Vinyl Stickers', 'Labels', 'Outdoor vinyl stickers.', 'Label 5x8', 'White Vinyl', $d, 1, 4, 0, 500, null, null, null, 1, ['Cut-to-size']],
        ['Specialty Stickers', 'Labels', 'Specialty / textured adhesive labels.', 'Label 5x8', 'Special Adhesive', $d, 1, 4, 0, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Seal Stickers', 'Labels', 'Circular seal / closure stickers.', 'Sticker Ø4', 'White adhesive', $d, 1, 1, 0, 2000, null, null, null, 1, ['Cut-to-size']],

        // ──────────── Stationery ────────────
        ['Letterhead A4', 'Stationery', 'Printed letterhead.', 'A4', 'Wood-Free 100', $o, 2, 4, 4, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Envelope DL', 'Stationery', 'Printed DL envelope.', 'DL', 'Wood-Free 80', $o, 1, 2, 0, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Envelope C5', 'Stationery', 'Printed C5 envelope (for A5 contents).', 'Envelope 16x23', 'Wood-Free 80', $o, 1, 2, 0, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Envelope A4', 'Stationery', 'Printed A4 envelope.', 'Envelope 23x32', 'Wood-Free 80', $o, 1, 2, 0, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Compliment Slip', 'Stationery', 'DL compliment slip.', 'DL', 'Wood-Free 100', $o, 2, 4, 4, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Note Pad', 'Stationery', 'Glued note pad with backboard.', 'A5', 'Wood-Free 80', $o, 1, 1, 0, 200, null, null, null, 50, ['Pad Gluing', 'Backboard']],
        ['Note Pad (Wire-O)', 'Stationery', 'Wire-O bound notepad.', 'A5', 'Wood-Free 80', $o, 1, 1, 0, 200, null, null, null, 50, ['Wire-O Binding', 'Backboard']],
        ['NCR Duplicate Pad', 'Stationery', '2-part carbonless duplicate pad.', 'A5', 'NCR (carbonless)', $o, 1, 1, 0, 100, null, null, null, 1, ['Numbering', 'Pad Gluing']],
        ['NCR Triplicate Set', 'Stationery', '3-part carbonless set with numbering.', 'A5', 'NCR (carbonless)', $o, 1, 1, 0, 100, null, null, null, 1, ['Numbering', 'Perforation', 'Saddle Stitching']],
        ['NCR Booklet', 'Stationery', 'Numbered carbonless booklet.', 'A5', 'NCR (carbonless)', $o, 1, 1, 0, 100, null, null, null, 1, ['Numbering', 'Perforation', 'Saddle Stitching']],
        ['Invoice / Receipt Book', 'Stationery', 'NCR invoice or receipt book, bound.', 'A5', 'NCR (carbonless)', $o, 1, 1, 0, 50, null, null, null, 1, ['Numbering', 'Saddle Stitching', 'Perforation']],
        ['Presentation Folder', 'Stationery', 'Printed presentation folder with pocket.', 'A4', 'Coated 300', $o, 2, 4, 4, 200, null, null, null, 1, ['Diecut', 'Scoring', 'Gluing', 'Gluing Pocket']],
        ['File with Pocket', 'Stationery', 'Printed file / wallet with pocket.', 'A4', 'Coated 300', $o, 2, 4, 4, 200, null, null, null, 1, ['Diecut', 'Scoring', 'Gluing', 'Gluing Pocket']],
        ['ID Badge', 'Stationery', 'Printed ID badge with lanyard hole.', 'Business Card', 'Coated 350', $o, 2, 4, 4, 500, null, null, null, 1, ['Diecut', 'Cut-to-size']],
        ['Ribbon Bookmarks', 'Stationery', 'Printed ribbon bookmark.', 'Pochette 7x15', 'Invercoat 220', $o, 1, 2, 0, 1000, null, null, null, 1, ['Cut-to-size', 'Ribbon']],

        // ──────────── Cards ────────────
        ['Business Cards', 'Cards', 'Double-sided business cards.', 'Business Card', 'Coated 350', $o, 2, 4, 4, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Business Cards (Digital)', 'Cards', 'Small-run digital business cards.', 'Business Card', 'Coated 350', $d, 2, 4, 4, 250, null, null, null, 1, ['Cut-to-size']],
        ['Business Cards + Lamination', 'Cards', 'Laminated business cards.', 'Business Card', 'Coated 350', $o, 2, 4, 4, 1000, null, null, null, 1, ['Lamination Matt', 'Cut-to-size']],
        ['Compliment / Gift Card', 'Cards', 'Laminated card with rounded corners.', 'Business Card', 'Coated 350', $o, 2, 4, 4, 600, null, null, null, 1, ['Lamination Glossy', 'Rounded Corners']],
        ['Loyalty Card', 'Cards', 'Stamp card / loyalty card.', 'Business Card', 'Coated 300', $o, 2, 4, 4, 1000, null, null, null, 1, ['Cut-to-size']],
        ['Drop Card', 'Cards', 'Small promotional drop card.', 'Business Card', 'Coated 350', $o, 2, 4, 4, 1000, null, null, null, 1, ['Lamination Matt', 'Cut-to-size']],
        ['VIP Card', 'Cards', 'Premium VIP / membership card.', 'Business Card', 'Invercoat 350', $o, 2, 4, 4, 500, null, null, null, 1, ['Hot-foil', 'Lamination Matt', 'Rounded Corners']],
        ['Tent Card', 'Cards', 'Folded tent / table card.', 'A5', 'Coated 350', $o, 2, 4, 4, 200, null, null, null, 1, ['Scoring', 'Cut-to-size']],
        ['Postcard (A5)', 'Cards', 'Mailing postcard A5.', 'A5', 'Coated 350', $o, 2, 4, 4, 2000, null, null, null, 1, ['Cut-to-size']],
        ['Invitation Card', 'Cards', 'Premium invitation card with foil.', 'A5', 'Invercoat 350', $o, 2, 4, 4, 200, null, null, null, 1, ['Hot-foil', 'Diecut']],

        // ──────────── Hardcover ────────────
        ['Hardcover Book (Board + Wrap)', 'Hardcovers', 'Full rigid hardcover, sewn or perfect bound.', 'A5', 'Greyboard (hardbox)', $o, 2, 4, 0, 100, null, null, 2.0, 120, ['Hardbox Operation', 'Covering', 'Perfect Binding']],
        ['Ring Binder', 'Hardcovers', 'Printed ring binder cover.', 'A4', 'Coated 350', $o, 2, 4, 4, 200, null, null, 3.0, 1, ['Lamination Matt', 'Diecut', 'Hardbox Operation']],
        ['Slipcase', 'Hardcovers', 'Rigid slipcase for books / sets.', 'A5', 'Coated 170 on Greyboard', $o, 2, 4, 4, 100, null, null, 3.0, 1, ['Diecut', 'Hardbox Operation', 'Covering']],
        ['Presentation Box', 'Hardcovers', 'Rigid presentation / gift box with lid.', 'Square 15', 'Coated 170 on Greyboard', $o, 2, 4, 4, 100, null, null, 5.0, 1, ['Diecut', 'Hardbox Operation', 'Covering', 'Magnet Closure']],

        // ──────────── Special / Large Format ────────────
        ['Furniture Wrap', 'Special', 'Printed furniture wrap / cover.', 'A3', 'Coated 150', $o, 1, 4, 0, 100, null, null, null, 1, ['Cut-to-size']],
        ['Envelope Stuffing', 'Special', 'Collation and envelope stuffing service.', 'DL', 'Wood-Free 80', $o, 1, 1, 0, 500, null, null, null, 1, ['Assembling']],
        ['Shrink Wrap Set', 'Special', 'Shrink-wrapped product set.', 'A4', null, $o, 1, 0, 0, 100, null, null, null, 1, ['Assembling']],
    ];
    return $products;
}

try {
    // ---------------------------------------------------------------
    // 1. Schema
    // ---------------------------------------------------------------
    $tables = [
        'pq_papers' => "CREATE TABLE IF NOT EXISTS `pq_papers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(200) NOT NULL,
            `gsm` INT UNSIGNED NOT NULL DEFAULT 0, `type` VARCHAR(50) DEFAULT NULL,
            `sheet_w_cm` DECIMAL(6,2) NOT NULL DEFAULT 70.00, `sheet_h_cm` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            `cost_per_sheet` DECIMAL(12,4) NOT NULL DEFAULT 0.0000, `notes` VARCHAR(255) DEFAULT NULL,
            `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'pq_machines' => "CREATE TABLE IF NOT EXISTS `pq_machines` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(200) NOT NULL,
            `method` VARCHAR(10) NOT NULL DEFAULT 'offset',
            `max_sheet_w_cm` DECIMAL(6,2) NOT NULL DEFAULT 70.00, `max_sheet_h_cm` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            `colors` TINYINT UNSIGNED NOT NULL DEFAULT 4, `plate_cost` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            `makeready_cost_per_color` DECIMAL(12,4) NOT NULL DEFAULT 0.0000, `run_cost_per_1000` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            `click_cost_per_sheet` DECIMAL(12,4) NOT NULL DEFAULT 0.0000, `min_charge` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `notes` VARCHAR(255) DEFAULT NULL, `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'pq_finishing' => "CREATE TABLE IF NOT EXISTS `pq_finishing` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(200) NOT NULL,
            `pricing_model` VARCHAR(20) NOT NULL DEFAULT 'per_sheet', `unit_cost` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            `setup_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `min_charge` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `notes` VARCHAR(255) DEFAULT NULL, `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'pq_sizes' => "CREATE TABLE IF NOT EXISTS `pq_sizes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(200) NOT NULL,
            `width_cm` DECIMAL(6,2) NOT NULL DEFAULT 0.00, `height_cm` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `category` VARCHAR(50) DEFAULT NULL, `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'pq_products' => "CREATE TABLE IF NOT EXISTS `pq_products` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(200) NOT NULL,
            `category` VARCHAR(100) DEFAULT NULL, `description` TEXT DEFAULT NULL,
            `default_size_id` INT UNSIGNED DEFAULT NULL, `default_paper_id` INT UNSIGNED DEFAULT NULL,
            `default_method` VARCHAR(10) NOT NULL DEFAULT 'offset', `default_machine_id` INT UNSIGNED DEFAULT NULL,
            `default_sides` TINYINT UNSIGNED NOT NULL DEFAULT 1, `default_colors_front` TINYINT UNSIGNED NOT NULL DEFAULT 4,
            `default_colors_back` TINYINT UNSIGNED NOT NULL DEFAULT 0, `default_qty` INT UNSIGNED NOT NULL DEFAULT 1000,
            `default_flat_w_cm` DECIMAL(6,2) DEFAULT NULL, `default_flat_h_cm` DECIMAL(6,2) DEFAULT NULL,
            `default_depth_cm` DECIMAL(6,2) DEFAULT NULL, `default_pages` INT UNSIGNED NOT NULL DEFAULT 1,
            `finishing_ids` JSON DEFAULT NULL, `bleed_mm` DECIMAL(5,2) NOT NULL DEFAULT 3.00, `gutter_mm` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
            `image` VARCHAR(255) DEFAULT NULL, `active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'pq_quote_specs' => "CREATE TABLE IF NOT EXISTS `pq_quote_specs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `quote_id` INT UNSIGNED NOT NULL, `quote_item_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(200) DEFAULT NULL,
            `product_id` INT UNSIGNED DEFAULT NULL, `product_name` VARCHAR(200) DEFAULT NULL,
            `size_w_cm` DECIMAL(6,2) NOT NULL DEFAULT 0.00, `size_h_cm` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `depth_cm` DECIMAL(6,2) DEFAULT NULL,
            `pages` INT UNSIGNED NOT NULL DEFAULT 1,
            `cover_paper_id` INT UNSIGNED DEFAULT NULL, `cover_paper_name` VARCHAR(200) DEFAULT NULL,
            `paper_id` INT UNSIGNED DEFAULT NULL, `paper_name` VARCHAR(200) DEFAULT NULL,
            `method` VARCHAR(10) NOT NULL DEFAULT 'offset', `machine_id` INT UNSIGNED DEFAULT NULL, `machine_name` VARCHAR(200) DEFAULT NULL,
            `colors_front` TINYINT UNSIGNED NOT NULL DEFAULT 0, `colors_back` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `sides` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `finishing_ids` JSON DEFAULT NULL, `finishing_names` VARCHAR(500) DEFAULT NULL,
            `spec_lines` JSON DEFAULT NULL, `options` JSON DEFAULT NULL, `breakdown` JSON DEFAULT NULL,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_pqs_quote` (`quote_id`), KEY `idx_pqs_item` (`quote_item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'pq_quote_tiers' => "CREATE TABLE IF NOT EXISTS `pq_quote_tiers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `quote_id` INT UNSIGNED NOT NULL, `quote_item_id` INT UNSIGNED NOT NULL,
            `label` VARCHAR(100) NOT NULL, `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
            `unit_price` DECIMAL(12,4) NOT NULL DEFAULT 0.0000, `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `unit_cost` DECIMAL(12,4) NOT NULL DEFAULT 0.0000, `price_mode` VARCHAR(10) NOT NULL DEFAULT 'engine',
            `is_primary` TINYINT UNSIGNED NOT NULL DEFAULT 0, `breakdown` JSON DEFAULT NULL,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_pqt_quote` (`quote_id`), KEY `idx_pqt_item` (`quote_item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $name => $sql) { $db->exec($sql); step($log, "Table <code>$name</code> ready"); }

    // Upgrade path: add newer columns to pq_quote_specs if an older install exists
    foreach ([
        'title' => "ALTER TABLE `pq_quote_specs` ADD COLUMN `title` VARCHAR(200) DEFAULT NULL AFTER `quote_item_id`",
        'depth_cm' => "ALTER TABLE `pq_quote_specs` ADD COLUMN `depth_cm` DECIMAL(6,2) DEFAULT NULL",
        'spec_lines' => "ALTER TABLE `pq_quote_specs` ADD COLUMN `spec_lines` JSON DEFAULT NULL",
        'options' => "ALTER TABLE `pq_quote_specs` ADD COLUMN `options` JSON DEFAULT NULL",
        'sort_order' => "ALTER TABLE `pq_quote_specs` ADD COLUMN `sort_order` INT UNSIGNED NOT NULL DEFAULT 0",
        'pages' => "ALTER TABLE `pq_quote_specs` ADD COLUMN `pages` INT UNSIGNED NOT NULL DEFAULT 1",
        'cover_paper_id' => "ALTER TABLE `pq_quote_specs` ADD COLUMN `cover_paper_id` INT UNSIGNED DEFAULT NULL",
        'cover_paper_name' => "ALTER TABLE `pq_quote_specs` ADD COLUMN `cover_paper_name` VARCHAR(200) DEFAULT NULL",
    ] as $col => $alter) {
        if (!columnExists($db, 'pq_quote_specs', $col)) { $db->exec($alter); step($log, "Added column <code>pq_quote_specs.$col</code>"); }
    }
    // Add flat dimensions + depth to pq_products for bag presets
    foreach ([
        'default_flat_w_cm' => "ALTER TABLE `pq_products` ADD COLUMN `default_flat_w_cm` DECIMAL(6,2) DEFAULT NULL",
        'default_flat_h_cm' => "ALTER TABLE `pq_products` ADD COLUMN `default_flat_h_cm` DECIMAL(6,2) DEFAULT NULL",
        'default_depth_cm' => "ALTER TABLE `pq_products` ADD COLUMN `default_depth_cm` DECIMAL(6,2) DEFAULT NULL",
    ] as $col => $alter) {
        if (!columnExists($db, 'pq_products', $col)) { $db->exec($alter); step($log, "Added column <code>pq_products.$col</code>"); }
    }
    // Add pages column for booklets
    if (!columnExists($db, 'pq_products', 'default_pages')) {
        $db->exec("ALTER TABLE `pq_products` ADD COLUMN `default_pages` INT UNSIGNED NOT NULL DEFAULT 1");
        step($log, "Added column <code>pq_products.default_pages</code>");
    }

    // ---- Cost-sheet upgrade (pricing v2): per-ton paper pricing + stored worksheets ----
    foreach ([
        'price_per_ton' => "ALTER TABLE `pq_papers` ADD COLUMN `price_per_ton` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `cost_per_sheet`",
        'price_mode' => "ALTER TABLE `pq_papers` ADD COLUMN `price_mode` VARCHAR(10) NOT NULL DEFAULT 'per_sheet' AFTER `price_per_ton`",
    ] as $col => $alter) {
        if (!columnExists($db, 'pq_papers', $col)) { $db->exec($alter); step($log, "Added column <code>pq_papers.$col</code>"); }
    }
    if (!columnExists($db, 'pq_quote_specs', 'cost_sheet')) {
        $db->exec("ALTER TABLE `pq_quote_specs` ADD COLUMN `cost_sheet` JSON DEFAULT NULL");
        step($log, "Added column <code>pq_quote_specs.cost_sheet</code> (job cost worksheets)");
    }
    if (!columnExists($db, 'pq_products', 'component_templates')) {
        $db->exec("ALTER TABLE `pq_products` ADD COLUMN `component_templates` JSON DEFAULT NULL");
        step($log, "Added column <code>pq_products.component_templates</code>");
    }
    // Backfill multi-component templates for packaging presets (only where not set yet).
    // Format: [{label, paper (name matched against pq_papers), piece:'flat'|fixed w/h, pieces_per_product}]
    $componentTemplates = [
        'Shopping Bag' => [
            ['label' => 'Bag body', 'paper' => 'Invercoat 220', 'piece' => 'flat'],
            ['label' => 'Tongue / reinforcement', 'paper' => 'Coated 200', 'piece_w_cm' => 9, 'piece_h_cm' => 31, 'pieces_per_product' => 1],
        ],
        'Small Gift Bag' => [
            ['label' => 'Bag body', 'paper' => 'Invercoat 220', 'piece' => 'flat'],
            ['label' => 'Tongue / reinforcement', 'paper' => 'Coated 200', 'piece_w_cm' => 7, 'piece_h_cm' => 24, 'pieces_per_product' => 1],
        ],
        'Medium Gift Bag' => [
            ['label' => 'Bag body', 'paper' => 'Invercoat 220', 'piece' => 'flat'],
            ['label' => 'Tongue / reinforcement', 'paper' => 'Coated 200', 'piece_w_cm' => 8, 'piece_h_cm' => 28, 'pieces_per_product' => 1],
        ],
        'Large Shopping Bag' => [
            ['label' => 'Bag body', 'paper' => 'Invercoat 250', 'piece' => 'flat'],
            ['label' => 'Tongue / reinforcement', 'paper' => 'Coated 200', 'piece_w_cm' => 11, 'piece_h_cm' => 40, 'pieces_per_product' => 1],
        ],
        'Luxury Ribbon Bag' => [
            ['label' => 'Bag body', 'paper' => 'Invercoat 250', 'piece' => 'flat'],
            ['label' => 'Tongue / reinforcement', 'paper' => 'Coated 200', 'piece_w_cm' => 9, 'piece_h_cm' => 35, 'pieces_per_product' => 1],
        ],
        'Soft Box (with insert)' => [
            ['label' => 'Box body', 'paper' => 'Invercoat 350', 'piece' => 'flat'],
            ['label' => 'Insert', 'paper' => 'Invercoat 350', 'piece' => 'flat', 'pieces_per_product' => 1],
        ],
        'Drawer Box' => [
            ['label' => 'Drawer', 'paper' => 'Invercoat 300', 'piece' => 'flat'],
            ['label' => 'Sleeve', 'paper' => 'Invercoat 300', 'piece' => 'flat', 'pieces_per_product' => 1],
        ],
        'Magnet Closure Box' => [
            ['label' => 'Outer shell', 'paper' => 'Invercoat 350', 'piece' => 'flat'],
            ['label' => 'Inner tray', 'paper' => 'Liner 250', 'piece' => 'flat', 'pieces_per_product' => 1],
        ],
        'Gift Box (Lid + Base)' => [
            ['label' => 'Base', 'paper' => 'Invercoat 350', 'piece' => 'flat'],
            ['label' => 'Lid', 'paper' => 'Invercoat 350', 'piece' => 'flat', 'pieces_per_product' => 1],
        ],
        'Presentation Box' => [
            ['label' => 'Outer shell', 'paper' => 'Coated 170 on Greyboard', 'piece' => 'flat'],
            ['label' => 'Inner tray', 'paper' => 'Liner 250', 'piece' => 'flat', 'pieces_per_product' => 1],
        ],
    ];
    $ctCount = 0;
    foreach ($componentTemplates as $prodName => $tpl) {
        $row = dbFetch($db, "SELECT id, component_templates FROM pq_products WHERE name=?", [$prodName]);
        if ($row && empty($row['component_templates'])) {
            dbUpdate($db, 'pq_products', ['component_templates' => json_encode($tpl)], 'id = ?', [$row['id']]);
            $ctCount++;
        }
    }
    if ($ctCount) step($log, "Seeded component templates for $ctCount packaging presets (bags: body + tongue, boxes: body + insert)");

    // Fix schema drift: the app reads/writes users.full_name which was missing from the live DB.
    if (!columnExists($db, 'users', 'full_name')) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `full_name` VARCHAR(200) DEFAULT NULL AFTER `last_name`");
        $db->exec("UPDATE `users` SET `full_name` = NULLIF(TRIM(CONCAT_WS(' ', `first_name`, `last_name`)), '') WHERE `full_name` IS NULL OR `full_name`=''");
        step($log, "Added &amp; backfilled <code>users.full_name</code> (was missing — names now display)");
    }

    // ---------------------------------------------------------------
    // 2. Engine + document settings
    // ---------------------------------------------------------------
    $seedSetting = function($db, $key, $val, $cat) {
        if (!dbFetch($db, "SELECT id FROM settings WHERE setting_key=?", [$key])) {
            dbInsert($db, 'settings', ['setting_key' => $key, 'setting_value' => $val, 'setting_type' => 'string', 'category' => $cat]);
        }
    };
    // Engine pricing knobs
    foreach ([
        'pq_currency_symbol' => '$', 'pq_markup_pct' => '120', 'pq_waste_pct' => '10', 'pq_setup_waste_sheets' => '80',
        'pq_vat_pct' => '11', 'pq_bleed_mm' => '3', 'pq_gutter_mm' => '5', 'pq_min_margin_pct' => '15', 'pq_price_rounding' => '5',
        'pq_job_minimum' => '50', 'pq_grip_margin_mm' => '10',
    ] as $k => $v) $seedSetting($db, $k, $v, 'quote_engine');
    // Quotation document text
    foreach ([
        'pq_intro' => 'We have the pleasure to submit to you our quotation with the following specifications:',
        'pq_payment_terms' => '50% upon order & 50% upon delivery.',
        'pq_signatory' => 'Samer Jawhar',
        'pq_signatories' => 'Samer Jawhar,Nicolas Dahan',
    ] as $k => $v) $seedSetting($db, $k, $v, 'quote_doc');
    // Company / letterhead
    foreach ([
        'company_name' => 'Aleph', 'company_legal_name' => 'Aleph Printing — Ghi Dahan & Partners',
        'company_email' => 'fabrication@aleph.com.lb', 'company_phone' => '+961 1 685 354 / 355',
        'company_fax' => '+961 1 687 082', 'company_website' => 'www.aleph.com.lb',
        'company_address' => 'Mekalles – Eliane Building, P.O.Box 147 Mansourieh El Metn, 1253 2020 Lebanon',
        'company_rcb' => '18872', 'company_tva' => '248620-601', 'currency_symbol' => '$',
    ] as $k => $v) $seedSetting($db, $k, $v, 'company');
    step($log, "Engine, document &amp; company settings seeded");

    // ---------------------------------------------------------------
    // 3. Module + admin permissions
    // ---------------------------------------------------------------
    $mod = dbFetch($db, "SELECT id FROM modules WHERE slug='quote_builder'");
    $modId = $mod ? $mod['id'] : dbInsert($db, 'modules', ['slug' => 'quote_builder', 'label' => 'Quote Builder', 'category' => 'sales', 'sort_order' => 20]);
    foreach (dbFetchAll($db, "SELECT id FROM users WHERE role='admin'") as $a) {
        if (!dbFetch($db, "SELECT id FROM user_modules WHERE user_id=? AND module_id=?", [$a['id'], $modId])) {
            dbInsert($db, 'user_modules', ['user_id' => $a['id'], 'module_id' => $modId, 'can_view' => 1, 'can_create' => 1, 'can_edit' => 1, 'can_delete' => 1, 'active' => 1]);
        }
    }
    step($log, "Module <code>quote_builder</code> registered and granted to admins");

    // ---------------------------------------------------------------
    // 4. Seed real Aleph catalog (only when each table is empty)
    // ---------------------------------------------------------------
    $seeded = [];

    if (!dbFetch($db, "SELECT id FROM pq_sizes LIMIT 1")) {
        $sizes = [
            ['Business Card', 9.0, 5.5, 'Card'], ['Sticker Ø4', 4.0, 4.0, 'Label'], ['Label 5x8', 5.0, 8.0, 'Label'],
            ['Pochette 7x15', 7.0, 15.0, 'Sleeve'], ['DL', 9.9, 21.0, 'Flyer'], ['A6', 10.5, 14.8, 'Flyer'],
            ['A5', 14.85, 21.0, 'Flyer'], ['A4', 21.0, 29.7, 'Sheet'], ['A3', 29.7, 42.0, 'Poster'],
            ['Square 15', 15.0, 15.0, 'Card'], ['Square 17.5', 17.5, 17.5, 'Card'], ['Square 20', 20.0, 20.0, 'Card'],
            ['Square 21', 21.0, 21.0, 'Card'], ['Desk Calendar 21.5x15.5', 21.5, 15.5, 'Calendar'],
            ['A2', 42.0, 59.4, 'Poster'], ['A1', 59.4, 84.1, 'Poster'],
            ['DL Landscape', 22.0, 11.0, 'Flyer'], ['Envelope 16x23', 16.0, 23.0, 'Envelope'],
            ['Envelope 23x32', 23.0, 32.0, 'Envelope'], ['Roll-up 85x200', 85.0, 200.0, 'Banner'],
            ['CD Insert', 12.0, 12.0, 'Card'],
        ];
        foreach ($sizes as $s) dbInsert($db, 'pq_sizes', ['name' => $s[0], 'width_cm' => $s[1], 'height_cm' => $s[2], 'category' => $s[3]]);
        $seeded[] = count($sizes) . ' sizes';
    }

    if (!dbFetch($db, "SELECT id FROM pq_papers LIMIT 1")) {
        // name, gsm, type, sheetW, sheetH, cost/sheet(USD) — calibrated from 308 real quotations
        $papers = [
            // Uncoated / Wood-free
            ['Wood-Free 40', 40, 'uncoated', 70, 100, 0.05],
            ['Wood-Free 45', 45, 'uncoated', 70, 100, 0.05], ['Wood-Free 80', 80, 'uncoated', 70, 100, 0.08],
            ['Wood-Free 90', 90, 'uncoated', 70, 100, 0.09],
            ['Wood-Free 100', 100, 'uncoated', 70, 100, 0.10], ['Wood-Free 120', 120, 'uncoated', 70, 100, 0.12],
            ['Bible 40', 40, 'uncoated', 70, 100, 0.04],
            // Coated
            ['Coated 115', 115, 'coated', 70, 100, 0.10],
            ['Coated 135', 135, 'coated', 70, 100, 0.12],
            ['Coated 150', 150, 'coated', 70, 100, 0.13], ['Coated 170', 170, 'coated', 70, 100, 0.16],
            ['Coated 200', 200, 'coated', 70, 100, 0.20], ['Coated 250', 250, 'coated', 70, 100, 0.26],
            ['Coated 300', 300, 'coated', 70, 100, 0.32], ['Coated 350', 350, 'coated', 70, 100, 0.38],
            ['Coated 400', 400, 'coated', 70, 100, 0.45],
            ['Coated Matt 170', 170, 'coated', 70, 100, 0.16],
            ['Coated Matt 200', 200, 'coated', 70, 100, 0.20],
            ['Coated Matt 350', 350, 'coated', 70, 100, 0.38],
            ['Coated Glossy 350', 350, 'coated', 70, 100, 0.40],
            // Artboard / Invercoat
            ['Invercoat 220', 220, 'artboard', 70, 100, 0.22],
            ['Invercoat 250', 250, 'artboard', 70, 100, 0.26], ['Invercoat 300', 300, 'artboard', 70, 100, 0.32],
            ['Invercoat 350', 350, 'artboard', 70, 100, 0.40],
            // Specialty
            ['Duplex 335', 335, 'duplex', 70, 100, 0.30],
            ['Triplex 440', 440, 'duplex', 70, 100, 0.42],
            ['CKB 350', 350, 'kraft', 70, 100, 0.32],
            ['CKB 390 (Kraft)', 390, 'kraft', 70, 100, 0.35],
            ['Murillo Bianco 190', 190, 'coated', 70, 100, 0.22],
            ['Greyboard (hardbox)', 0, 'board', 70, 100, 0.45],
            ['Coated 170 on Greyboard', 170, 'laminated_board', 70, 100, 0.28],
            ['Coated 350 on Greyboard', 350, 'laminated_board', 70, 100, 0.50],
            ['NCR (carbonless)', 0, 'ncr', 70, 100, 0.07],
            ['White adhesive', 0, 'adhesive', 70, 100, 0.25],
            ['Special Adhesive', 0, 'adhesive', 70, 100, 0.32],
            ['White Vinyl', 0, 'vinyl', 70, 100, 0.38],
            ['Cialux (covering)', 0, 'covering', 70, 100, 0.32],
            ['Cotton', 120, 'cotton', 70, 100, 0.25],
            ['PP (Polypropylene)', 0, 'plastic', 70, 100, 0.18],
            ['Acetate', 0, 'plastic', 70, 100, 0.15],
            ['Liner 250', 250, 'liner', 70, 100, 0.20],
        ];
        foreach ($papers as $p) dbInsert($db, 'pq_papers', ['name' => $p[0], 'gsm' => $p[1], 'type' => $p[2], 'sheet_w_cm' => $p[3], 'sheet_h_cm' => $p[4], 'cost_per_sheet' => $p[5]]);
        $seeded[] = count($papers) . ' papers';
    }

    if (!dbFetch($db, "SELECT id FROM pq_finishing LIMIT 1")) {
        // name, model, unit_cost, setup, min — calibrated from 308 real quotations
        $finishing = [
            // Cutting / Shaping
            ['Diecut', 'per_sheet', 0.15, 40, 30],
            ['Diecut (Slitting)', 'per_sheet', 0.08, 25, 20],
            ['Slitting', 'per_sheet', 0.04, 10, 8],
            ['Cut-to-size', 'per_sheet', 0.02, 8, 5],
            ['Laser Cut', 'per_sheet', 0.20, 30, 25],
            ['Punching', 'per_piece', 0.10, 8, 5],
            ['Rounded Corners', 'per_piece', 0.30, 15, 12],
            ['Window', 'per_sheet', 0.08, 15, 12],
            // Lamination / Coating
            ['Lamination Matt', 'per_sheet', 0.08, 10, 8],
            ['Lamination Glossy', 'per_sheet', 0.08, 10, 8],
            ['Lamination Velvet', 'per_sheet', 0.25, 20, 15],
            ['Lamination R/V', 'per_sheet', 0.12, 15, 12],
            ['Lamination Matt R/V', 'per_sheet', 0.12, 15, 12],
            ['Lamination Glossy R/V', 'per_sheet', 0.12, 15, 12],
            ['Lamination on Cover', 'per_sheet', 0.12, 12, 10],
            ['Matt Lamination', 'per_sheet', 0.08, 10, 8],
            ['Glossy Lamination', 'per_sheet', 0.08, 10, 8],
            ['Matt Varnish', 'per_sheet', 0.05, 10, 8],
            ['Aqueous Coating', 'per_sheet', 0.05, 10, 8],
            ['Spot UV', 'per_sheet', 0.25, 30, 25],
            ['Encapsulation', 'per_sheet', 0.10, 10, 8],
            ['UV Coating', 'per_sheet', 0.08, 10, 8],
            // Foil / Emboss
            ['Hot-foil', 'per_piece', 1.50, 60, 40],
            ['Hot-foil Silver', 'per_piece', 1.80, 60, 40],
            ['Embossing', 'per_piece', 3.00, 60, 40],
            ['Debossing', 'per_piece', 3.00, 60, 40],
            ['Embossing on Cover', 'per_piece', 3.50, 60, 40],
            ['Debossing on Cover', 'per_piece', 3.50, 60, 40],
            // Folding / Creasing
            ['Folding', 'per_piece', 0.03, 10, 8],
            ['Scoring', 'per_sheet', 0.06, 10, 8],
            ['Perforation', 'per_sheet', 0.04, 10, 8],
            ['Creasing', 'per_sheet', 0.04, 10, 8],
            ['SDF', 'per_sheet', 0.04, 8, 5],
            // Binding
            ['Saddle Stitching', 'per_piece', 0.30, 15, 12],
            ['Side Stitching', 'per_piece', 0.15, 10, 8],
            ['Perfect Binding', 'per_piece', 0.80, 30, 25],
            ['PUR Binding', 'per_piece', 1.00, 35, 30],
            ['Wire-O Binding', 'per_piece', 0.80, 30, 25],
            ['Case-Bound Binding', 'per_piece', 2.50, 50, 40],
            ['Sewing', 'per_piece', 5.00, 30, 25],
            // Gluing / Assembly
            ['Gluing', 'per_piece', 0.04, 10, 8],
            ['Gluing on Top', 'per_piece', 0.04, 10, 8],
            ['Gluing Back-to-back', 'per_piece', 0.06, 10, 8],
            ['Gluing Pocket', 'per_piece', 0.08, 10, 8],
            ['Gluing Pocket (Spine)', 'per_piece', 0.10, 12, 10],
            ['NCR Gluing', 'per_piece', 0.05, 10, 8],
            ['Pad Gluing', 'per_piece', 0.08, 10, 8],
            ['Assembling', 'per_piece', 0.15, 15, 12],
            ['Double-Sided Tape', 'per_piece', 0.04, 8, 5],
            // Packaging / Bags
            ['Handles', 'per_piece', 0.35, 15, 12],
            ['Handles GroGrain', 'per_piece', 0.50, 18, 15],
            ['Ribbon Handles', 'per_piece', 0.40, 15, 12],
            ['Rope Handles', 'per_piece', 0.35, 15, 12],
            ['Ribbon', 'per_piece', 0.10, 8, 8],
            ['Ribbon Closure', 'per_piece', 0.12, 10, 8],
            ['Bag Fabrication', 'per_piece', 0.50, 25, 20],
            ['Separators', 'per_piece', 0.30, 12, 10],
            ['Inner Holders', 'per_piece', 1.00, 20, 15],
            ['Inner Tray Gluing', 'per_piece', 0.15, 12, 10],
            // Hardcover
            ['Hardbox Operation', 'per_piece', 4.00, 60, 50],
            ['Hard-Case Operation', 'per_piece', 4.00, 60, 50],
            ['Hard-Case Fabrication', 'per_piece', 4.00, 60, 50],
            ['Hard-Box Fabrication', 'per_piece', 4.00, 60, 50],
            ['Covering', 'per_piece', 0.80, 30, 25],
            ['Flexi Covering', 'per_piece', 0.60, 25, 20],
            ['Backboard', 'per_piece', 0.20, 10, 8],
            ['Carton Base', 'per_piece', 0.25, 12, 10],
            // Specialty
            ['Numbering', 'per_piece', 0.03, 10, 8],
            ['Magnet Closure', 'per_piece', 1.50, 30, 20],
            ['Shrink Wrap', 'per_piece', 0.08, 8, 5],
            ['Index Tabs', 'per_1000', 20.00, 15, 12],
            ['Pocket', 'per_piece', 0.20, 12, 10],
            ['Acetate Cover', 'per_piece', 0.10, 10, 8],
        ];
        foreach ($finishing as $f) dbInsert($db, 'pq_finishing', ['name' => $f[0], 'pricing_model' => $f[1], 'unit_cost' => $f[2], 'setup_cost' => $f[3], 'min_charge' => $f[4]]);
        $seeded[] = count($finishing) . ' finishing options';
    }

    if (!dbFetch($db, "SELECT id FROM pq_products LIMIT 1")) {
        $sizeMap = []; foreach (dbFetchAll($db, "SELECT id,name FROM pq_sizes") as $r) $sizeMap[$r['name']] = (int)$r['id'];
        $paperMap = []; foreach (dbFetchAll($db, "SELECT id,name FROM pq_papers") as $r) $paperMap[$r['name']] = (int)$r['id'];
        $finMap = []; foreach (dbFetchAll($db, "SELECT id,name FROM pq_finishing") as $r) $finMap[$r['name']] = (int)$r['id'];

        $products = pq_product_list($sizeMap, $paperMap, $finMap);
        foreach ($products as $p) {
            $finIds = [];
            if (!empty($p[14])) foreach ($p[14] as $fn) if (isset($finMap[$fn])) $finIds[] = $finMap[$fn];
            dbInsert($db, 'pq_products', [
                'name' => $p[0], 'category' => $p[1], 'description' => $p[2],
                'default_size_id' => $sizeMap[$p[3]] ?? null, 'default_paper_id' => $paperMap[$p[4]] ?? null,
                'default_method' => $p[5], 'default_sides' => $p[6],
                'default_colors_front' => $p[7], 'default_colors_back' => $p[8], 'default_qty' => $p[9],
                'default_flat_w_cm' => $p[10], 'default_flat_h_cm' => $p[11], 'default_depth_cm' => $p[12],
                'default_pages' => $p[13],
                'finishing_ids' => json_encode($finIds), 'bleed_mm' => 3, 'gutter_mm' => 5,
            ]);
        }
        $seeded[] = count($products) . ' product presets';
    }

    // ---- Upsert: add missing papers (by name) from enriched catalog ----
    $existingPapers = []; foreach (dbFetchAll($db, "SELECT name FROM pq_papers") as $r) $existingPapers[$r['name']] = true;
    $allPapers = [
        ['Wood-Free 40', 40, 'uncoated', 70, 100, 0.05],
        ['Wood-Free 45', 45, 'uncoated', 70, 100, 0.05], ['Wood-Free 80', 80, 'uncoated', 70, 100, 0.08],
        ['Wood-Free 90', 90, 'uncoated', 70, 100, 0.09],
        ['Wood-Free 100', 100, 'uncoated', 70, 100, 0.10], ['Wood-Free 120', 120, 'uncoated', 70, 100, 0.12],
        ['Bible 40', 40, 'uncoated', 70, 100, 0.04],
        ['Coated 115', 115, 'coated', 70, 100, 0.10],
        ['Coated 135', 135, 'coated', 70, 100, 0.12],
        ['Coated 150', 150, 'coated', 70, 100, 0.13], ['Coated 170', 170, 'coated', 70, 100, 0.16],
        ['Coated 200', 200, 'coated', 70, 100, 0.20], ['Coated 250', 250, 'coated', 70, 100, 0.26],
        ['Coated 300', 300, 'coated', 70, 100, 0.32], ['Coated 350', 350, 'coated', 70, 100, 0.38],
        ['Coated 400', 400, 'coated', 70, 100, 0.45],
        ['Coated Matt 170', 170, 'coated', 70, 100, 0.16],
        ['Coated Matt 200', 200, 'coated', 70, 100, 0.20],
        ['Coated Matt 350', 350, 'coated', 70, 100, 0.38],
        ['Coated Glossy 350', 350, 'coated', 70, 100, 0.40],
        ['Invercoat 220', 220, 'artboard', 70, 100, 0.22],
        ['Invercoat 250', 250, 'artboard', 70, 100, 0.26], ['Invercoat 300', 300, 'artboard', 70, 100, 0.32],
        ['Invercoat 350', 350, 'artboard', 70, 100, 0.40],
        ['Duplex 335', 335, 'duplex', 70, 100, 0.30],
        ['Triplex 440', 440, 'duplex', 70, 100, 0.42],
        ['CKB 350', 350, 'kraft', 70, 100, 0.32],
        ['CKB 390 (Kraft)', 390, 'kraft', 70, 100, 0.35],
        ['Murillo Bianco 190', 190, 'coated', 70, 100, 0.22],
        ['Greyboard (hardbox)', 0, 'board', 70, 100, 0.45],
        ['Coated 170 on Greyboard', 170, 'laminated_board', 70, 100, 0.28],
        ['Coated 350 on Greyboard', 350, 'laminated_board', 70, 100, 0.50],
        ['NCR (carbonless)', 0, 'ncr', 70, 100, 0.07],
        ['White adhesive', 0, 'adhesive', 70, 100, 0.25],
        ['Special Adhesive', 0, 'adhesive', 70, 100, 0.32],
        ['White Vinyl', 0, 'vinyl', 70, 100, 0.38],
        ['Cialux (covering)', 0, 'covering', 70, 100, 0.32],
        ['Cotton', 120, 'cotton', 70, 100, 0.25],
        ['PP (Polypropylene)', 0, 'plastic', 70, 100, 0.18],
        ['Acetate', 0, 'plastic', 70, 100, 0.15],
        ['Liner 250', 250, 'liner', 70, 100, 0.20],
    ];
    $papersAdded = 0;
    foreach ($allPapers as $p) {
        if (isset($existingPapers[$p[0]])) continue;
        dbInsert($db, 'pq_papers', ['name' => $p[0], 'gsm' => $p[1], 'type' => $p[2], 'sheet_w_cm' => $p[3], 'sheet_h_cm' => $p[4], 'cost_per_sheet' => $p[5]]);
        $papersAdded++;
    }
    if ($papersAdded) $seeded[] = $papersAdded . ' new papers added';

    // ---- Upsert: add missing finishing options (by name) ----
    $existingFin = []; foreach (dbFetchAll($db, "SELECT name FROM pq_finishing") as $r) $existingFin[$r['name']] = true;
    $allFin = [
        ['Diecut', 'per_sheet', 0.15, 40, 30],
        ['Diecut (Slitting)', 'per_sheet', 0.08, 25, 20],
        ['Slitting', 'per_sheet', 0.04, 10, 8],
        ['Cut-to-size', 'per_sheet', 0.02, 8, 5],
        ['Laser Cut', 'per_sheet', 0.20, 30, 25],
        ['Punching', 'per_piece', 0.10, 8, 5],
        ['Rounded Corners', 'per_piece', 0.30, 15, 12],
        ['Window', 'per_sheet', 0.08, 15, 12],
        ['Lamination Matt', 'per_sheet', 0.08, 10, 8],
        ['Lamination Glossy', 'per_sheet', 0.08, 10, 8],
        ['Lamination Velvet', 'per_sheet', 0.25, 20, 15],
        ['Lamination R/V', 'per_sheet', 0.12, 15, 12],
        ['Lamination Matt R/V', 'per_sheet', 0.12, 15, 12],
        ['Lamination Glossy R/V', 'per_sheet', 0.12, 15, 12],
        ['Lamination on Cover', 'per_sheet', 0.12, 12, 10],
        ['Matt Lamination', 'per_sheet', 0.08, 10, 8],
        ['Glossy Lamination', 'per_sheet', 0.08, 10, 8],
        ['Matt Varnish', 'per_sheet', 0.05, 10, 8],
        ['Aqueous Coating', 'per_sheet', 0.05, 10, 8],
        ['Spot UV', 'per_sheet', 0.25, 30, 25],
        ['Encapsulation', 'per_sheet', 0.10, 10, 8],
        ['UV Coating', 'per_sheet', 0.08, 10, 8],
        ['Hot-foil', 'per_piece', 1.50, 60, 40],
        ['Hot-foil Silver', 'per_piece', 1.80, 60, 40],
        ['Embossing', 'per_piece', 3.00, 60, 40],
        ['Debossing', 'per_piece', 3.00, 60, 40],
        ['Embossing on Cover', 'per_piece', 3.50, 60, 40],
        ['Debossing on Cover', 'per_piece', 3.50, 60, 40],
        ['Folding', 'per_piece', 0.03, 10, 8],
        ['Scoring', 'per_sheet', 0.06, 10, 8],
        ['Perforation', 'per_sheet', 0.04, 10, 8],
        ['Creasing', 'per_sheet', 0.04, 10, 8],
        ['SDF', 'per_sheet', 0.04, 8, 5],
        ['Saddle Stitching', 'per_piece', 0.30, 15, 12],
        ['Side Stitching', 'per_piece', 0.15, 10, 8],
        ['Perfect Binding', 'per_piece', 0.80, 30, 25],
        ['PUR Binding', 'per_piece', 1.00, 35, 30],
        ['Wire-O Binding', 'per_piece', 0.80, 30, 25],
        ['Case-Bound Binding', 'per_piece', 2.50, 50, 40],
        ['Sewing', 'per_piece', 5.00, 30, 25],
        ['Gluing', 'per_piece', 0.04, 10, 8],
        ['Gluing on Top', 'per_piece', 0.04, 10, 8],
        ['Gluing Back-to-back', 'per_piece', 0.06, 10, 8],
        ['Gluing Pocket', 'per_piece', 0.08, 10, 8],
        ['Gluing Pocket (Spine)', 'per_piece', 0.10, 12, 10],
        ['NCR Gluing', 'per_piece', 0.05, 10, 8],
        ['Pad Gluing', 'per_piece', 0.08, 10, 8],
        ['Assembling', 'per_piece', 0.15, 15, 12],
        ['Double-Sided Tape', 'per_piece', 0.04, 8, 5],
        ['Handles', 'per_piece', 0.35, 15, 12],
        ['Handles GroGrain', 'per_piece', 0.50, 18, 15],
        ['Ribbon Handles', 'per_piece', 0.40, 15, 12],
        ['Rope Handles', 'per_piece', 0.35, 15, 12],
        ['Ribbon', 'per_piece', 0.10, 8, 8],
        ['Ribbon Closure', 'per_piece', 0.12, 10, 8],
        ['Bag Fabrication', 'per_piece', 0.50, 25, 20],
        ['Separators', 'per_piece', 0.30, 12, 10],
        ['Inner Holders', 'per_piece', 1.00, 20, 15],
        ['Inner Tray Gluing', 'per_piece', 0.15, 12, 10],
        ['Hardbox Operation', 'per_piece', 4.00, 60, 50],
        ['Hard-Case Operation', 'per_piece', 4.00, 60, 50],
        ['Hard-Case Fabrication', 'per_piece', 4.00, 60, 50],
        ['Hard-Box Fabrication', 'per_piece', 4.00, 60, 50],
        ['Covering', 'per_piece', 0.80, 30, 25],
        ['Flexi Covering', 'per_piece', 0.60, 25, 20],
        ['Backboard', 'per_piece', 0.20, 10, 8],
        ['Carton Base', 'per_piece', 0.25, 12, 10],
        ['Numbering', 'per_piece', 0.03, 10, 8],
        ['Magnet Closure', 'per_piece', 1.50, 30, 20],
        ['Shrink Wrap', 'per_piece', 0.08, 8, 5],
        ['Index Tabs', 'per_1000', 20.00, 15, 12],
        ['Pocket', 'per_piece', 0.20, 12, 10],
        ['Acetate Cover', 'per_piece', 0.10, 10, 8],
    ];
    $finAdded = 0;
    foreach ($allFin as $f) {
        if (isset($existingFin[$f[0]])) continue;
        dbInsert($db, 'pq_finishing', ['name' => $f[0], 'pricing_model' => $f[1], 'unit_cost' => $f[2], 'setup_cost' => $f[3], 'min_charge' => $f[4]]);
        $finAdded++;
    }
    if ($finAdded) $seeded[] = $finAdded . ' new finishing options added';

    // ---- Upsert: add missing size presets (by name) ----
    $existingSizes = []; foreach (dbFetchAll($db, "SELECT name FROM pq_sizes") as $r) $existingSizes[$r['name']] = true;
    $allSizes = [
        ['Business Card', 9.0, 5.5, 'Card'], ['Sticker Ø4', 4.0, 4.0, 'Label'], ['Label 5x8', 5.0, 8.0, 'Label'],
        ['Pochette 7x15', 7.0, 15.0, 'Sleeve'], ['DL', 9.9, 21.0, 'Flyer'], ['A6', 10.5, 14.8, 'Flyer'],
        ['A5', 14.85, 21.0, 'Flyer'], ['A4', 21.0, 29.7, 'Sheet'], ['A3', 29.7, 42.0, 'Poster'],
        ['Square 15', 15.0, 15.0, 'Card'], ['Square 17.5', 17.5, 17.5, 'Card'], ['Square 20', 20.0, 20.0, 'Card'],
        ['Square 21', 21.0, 21.0, 'Card'], ['Desk Calendar 21.5x15.5', 21.5, 15.5, 'Calendar'],
        ['A2', 42.0, 59.4, 'Poster'], ['A1', 59.4, 84.1, 'Poster'],
        ['DL Landscape', 22.0, 11.0, 'Flyer'], ['Envelope 16x23', 16.0, 23.0, 'Envelope'],
        ['Envelope 23x32', 23.0, 32.0, 'Envelope'], ['Roll-up 85x200', 85.0, 200.0, 'Banner'],
        ['CD Insert', 12.0, 12.0, 'Card'],
    ];
    $sizesAdded = 0;
    foreach ($allSizes as $s) {
        if (isset($existingSizes[$s[0]])) continue;
        dbInsert($db, 'pq_sizes', ['name' => $s[0], 'width_cm' => $s[1], 'height_cm' => $s[2], 'category' => $s[3]]);
        $sizesAdded++;
    }
    if ($sizesAdded) $seeded[] = $sizesAdded . ' new size presets added';

    // Upsert: add any new product presets that are missing (by name) from previous installs
    $existingNames = []; foreach (dbFetchAll($db, "SELECT name FROM pq_products") as $r) $existingNames[$r['name']] = true;
    $sizeMap = []; foreach (dbFetchAll($db, "SELECT id,name FROM pq_sizes") as $r) $sizeMap[$r['name']] = (int)$r['id'];
    $paperMap = []; foreach (dbFetchAll($db, "SELECT id,name FROM pq_papers") as $r) $paperMap[$r['name']] = (int)$r['id'];
    $finMap = []; foreach (dbFetchAll($db, "SELECT id,name FROM pq_finishing") as $r) $finMap[$r['name']] = (int)$r['id'];

    $products = pq_product_list($sizeMap, $paperMap, $finMap);
    $added = 0;
    foreach ($products as $p) {
        if (isset($existingNames[$p[0]])) continue;
        $finIds = [];
        if (!empty($p[14])) foreach ($p[14] as $fn) if (isset($finMap[$fn])) $finIds[] = $finMap[$fn];
        dbInsert($db, 'pq_products', [
            'name' => $p[0], 'category' => $p[1], 'description' => $p[2],
            'default_size_id' => $sizeMap[$p[3]] ?? null, 'default_paper_id' => $paperMap[$p[4]] ?? null,
            'default_method' => $p[5], 'default_sides' => $p[6],
            'default_colors_front' => $p[7], 'default_colors_back' => $p[8], 'default_qty' => $p[9],
            'default_flat_w_cm' => $p[10], 'default_flat_h_cm' => $p[11], 'default_depth_cm' => $p[12],
            'default_pages' => $p[13],
            'finishing_ids' => json_encode($finIds), 'bleed_mm' => 3, 'gutter_mm' => 5,
        ]);
        $added++;
    }
    if ($added) $seeded[] = $added . ' new product presets added';

    step($log, $seeded ? ('Seeded: ' . implode(', ', $seeded)) : 'Catalog already contained data — seeds skipped (nothing overwritten)');
    $success = true;
} catch (Throwable $e) {
    step($log, 'ERROR: ' . h($e->getMessage()), false);
    $success = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Quote Engine Installer</title>
    <style>
        body { font-family: Inter, Arial, sans-serif; background: #0f172a; color: #e2e8f0; padding: 40px; line-height: 1.6; }
        .box { max-width: 660px; margin: 0 auto; background: #1e293b; border-radius: 14px; padding: 32px; box-shadow: 0 20px 40px rgba(0,0,0,.4); }
        h1 { font-size: 22px; margin-bottom: 4px; } h1 span { color: #f25424; }
        .sub { color: #94a3b8; font-size: 13px; margin-bottom: 20px; }
        .row { padding: 8px 12px; border-radius: 8px; background: #0f172a; margin-bottom: 6px; font-size: 14px; display:flex; gap:10px; align-items:center; }
        .ok { color: #22c55e; } .bad { color: #ef4444; }
        code { background: #334155; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
        .cta { margin-top: 24px; } a.btn { display:inline-block; background:#f25424; color:#fff; text-decoration:none; padding:10px 18px; border-radius:8px; font-weight:600; }
        .warn { margin-top:20px; padding:12px 16px; background:#422006; border:1px solid #a16207; border-radius:8px; color:#fde68a; font-size:13px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Aleph <span>Quote Engine</span> Installer</h1>
        <div class="sub">Schema · real catalog · company letterhead · permissions</div>
        <?php foreach ($log as $l): ?>
            <div class="row"><span class="<?= $l['ok'] ? 'ok' : 'bad' ?>"><?= $l['ok'] ? 'OK' : 'ERR' ?></span><span><?= $l['msg'] ?></span></div>
        <?php endforeach; ?>
        <?php if (!empty($success)): ?>
            <div class="cta"><a class="btn" href="quote_add.php">Open the Quote Builder</a></div>
            <div class="warn"><strong>Next:</strong> review your real paper &amp; finishing costs under Catalog &amp; Pricing and set the signatory in Settings. Then <strong>delete <code>pq_setup.php</code></strong> from the server.</div>
        <?php else: ?>
            <div class="warn">Installation did not complete. Fix the error above and re-run — it is safe to run again.</div>
        <?php endif; ?>
    </div>
</body>
</html>
