"""
Aleph Quotation Parser v2
Reads 308 .doc/.docx files, extracts structured data → quotation_data.json
Handles Word table cell markers (\x07) for proper parsing.
"""
import os, re, json, glob, sys
from datetime import datetime

BASE = r'C:\Users\Samer\Downloads\CRM versite\aleph_system\Quotation Data - Samer'
OUT  = r'C:\Users\Samer\Downloads\CRM versite\aleph_system\quotation_data.json'

def clean(s):
    """Normalize whitespace but preserve \x07 for now."""
    s = s.replace('\r', ' ').replace('\n', ' ').replace('\u200b', '')
    s = s.replace('\ufeff', '').replace('\ufffd', '-')
    s = re.sub(r'[ \t]+', ' ', s)
    return s.strip()

def clean_final(s):
    """Full clean including \x07."""
    return clean(s.replace('\x07', ' '))

def parse_price(raw):
    m = re.search(r'([\d,]+(?:\.\d+)?)\s*\$', raw)
    if m:
        return float(m.group(1).replace(',', ''))
    return None

def parse_qty(raw):
    raw = raw.strip()
    m = re.search(r'([\d,]+)', raw)
    if m:
        val = m.group(1).replace(',', '')
        if val:
            return int(val)
    return None

def extract_customer(filename):
    name = os.path.splitext(filename)[0]
    if name == 'desktop.ini':
        return None
    parts = re.split(r'\s*[–\-]\s+', name, maxsplit=1)
    return parts[0].strip() if parts[0].strip() else None

def extract_product_name(text):
    """Extract the product title from a block (after the N ▸ marker has been stripped)."""
    # Clean \x07 and \r for title extraction only
    raw = text.replace('\x07', ' ').replace('\r', ' ')
    raw = re.sub(r'\s+', ' ', raw).strip()
    
    # The title is the first chunk of text before any spec keyword
    m = re.match(r'(.+?)(?:\s+(?:Size|Pages?|Paper|Printing|Finishing|Quantity|Item|Price)\s*[:\:]|\s*$)', raw, re.IGNORECASE)
    if m:
        title = m.group(1).strip()
        return title if title else None
    return None

def parse_specs(text):
    specs = {}
    for key in ['Size', 'Pages', 'Paper', 'Printing', 'Finishing']:
        m = re.search(rf'{key}\s*:\s*(.+?)(?=\s*(?:Size|Pages|Paper|Printing|Finishing|Quantity|Item|Price|Option|Total|\d+\s*[▸\-\–]|Payment|\Z))', text, re.IGNORECASE)
        if m:
            val = clean_final(m.group(1))
            if val and val not in [':', '']:
                specs[key.lower()] = val
    return specs

def parse_quantity_prices_from_cells(text):
    """
    Parse quantity/price pairs from Word table text.
    Word tables use \x07 as cell separator.
    Pattern: Quantity\x07Price header, then rows of: qty\x07price or label\x07price
    """
    items = []
    
    # Find the quantity section - after specs, before payment/footer
    # Split on \x07 to get individual cells
    cells = text.split('\x07')
    
    # Find where the Quantity/Price table starts
    qty_start = -1
    for i, cell in enumerate(cells):
        c = clean(cell).lower()
        if 'quantity' in c or c == 'item':
            qty_start = i + 1
            break
    
    if qty_start < 0:
        return items
    
    # Find where the table ends (payment terms, footer, or next item)
    qty_cells = []
    for i in range(qty_start, len(cells)):
        c = clean(cells[i])
        if any(kw in c.lower() for kw in ['payment terms', 'should you', 'sincerely', 'special discount']):
            break
        # Stop at next numbered item marker
        if re.match(r'^\d+\s*[▸\-\–]', c):
            break
        qty_cells.append(c)
    
    # Parse cells: they come in pairs (qty, price) or triplets (label, qty, price)
    # Skip empty cells
    non_empty = [c for c in qty_cells if c]
    
    # Try to parse as qty/price pairs
    # Pattern: "4,000" "260 $ + VAT" or "Option" "160 $ + VAT"
    i = 0
    while i < len(non_empty):
        cell = non_empty[i]
        
        # Check if next cell is a price
        if i + 1 < len(non_empty):
            price = parse_price(non_empty[i + 1])
            if price:
                qty = parse_qty(cell)
                is_option = 'option' in cell.lower() or cell.lower().startswith('add')
                label = clean_final(cell) if is_option else None
                items.append({
                    'quantity': qty,
                    'quantity_raw': clean_final(cell),
                    'price': price,
                    'is_option': is_option,
                    'label': label,
                })
                i += 2
                continue
        
        # Single cell with a price (like "Total price: 4,180 $")
        price = parse_price(cell)
        if price:
            items.append({
                'quantity': None,
                'quantity_raw': clean_final(cell),
                'price': price,
                'is_option': False,
                'label': None,
            })
            i += 1
            continue
        
        i += 1
    
    return items

def parse_table_with_sizes(text):
    """Parse complex tables with Item/Size/Qty/Price columns (like stickers)."""
    cells = text.split('\x07')
    
    # Find 'Item' header
    item_idx = -1
    for i, cell in enumerate(cells):
        if clean(cell).lower() == 'item':
            item_idx = i
            break
    
    if item_idx < 0:
        return None
    
    # Find total price
    total_price = None
    for cell in cells:
        m = re.search(r'Total price:\s*([\d,]+)\s*\$', cell)
        if m:
            total_price = float(m.group(1).replace(',', ''))
            break
    
    return {
        'total_price': total_price,
        'raw_cells': [clean_final(c) for c in cells[item_idx:] if clean(c)],
    }

def split_multi_items(text):
    """Split text into individual item blocks using \x07-aware splitting."""
    # Find all numbered item markers: "1 ▸", "2 ▸", etc. within cell markers
    # The marker looks like: " \x071 \x07▸ \x07Product Name"
    parts = re.split(r'(\d+)\s*[▸\-\–]\s*', text)
    
    if len(parts) <= 1:
        return [text]
    
    items = []
    for i in range(1, len(parts), 2):
        num = parts[i]
        content = parts[i + 1] if i + 1 < len(parts) else ''
        items.append((int(num), content))
    
    return items

def parse_file(fpath, word):
    """Parse a single .doc/.docx file and return structured data."""
    fname = os.path.basename(fpath)
    mtime = datetime.fromtimestamp(os.path.getmtime(fpath))
    customer = extract_customer(fname)
    
    if not customer:
        return None, 'No customer name in filename'
    
    try:
        if fpath.endswith('.docx'):
            from docx import Document
            docx_doc = Document(fpath)
            text = '\n'.join([p.text for p in docx_doc.paragraphs])
        else:
            doc = word.Documents.Open(fpath)
            text = doc.Content.Text
            doc.Close()
    except Exception as e:
        return None, str(e)
    
    # Skip empty template
    raw_text = text.replace('\x07', ' ').replace('\r', ' ')
    if re.search(r'Size\s*:\s*cm\s*Paper\s*:\s*gsm\s*Printing\s*:', raw_text) and parse_price(raw_text) is None:
        return None, 'Empty template'
    
    # Split into individual items
    item_blocks = split_multi_items(text)
    
    results = []
    for block in item_blocks:
        if isinstance(block, tuple):
            item_num, block_text = block
        else:
            item_num, block_text = None, block
        
        title = extract_product_name(block_text)
        specs = parse_specs(block_text)
        
        # Check for complex table (Item/Size/Qty/Price)
        table_info = parse_table_with_sizes(block_text)
        
        # Parse quantity/price pairs
        qty_prices = parse_quantity_prices_from_cells(block_text)
        
        # If no qty/price found via cell parsing, try regex fallback
        if not qty_prices:
            # Fallback: try to find prices with regex on the full text
            prices_found = re.findall(r'([\d,]+)\s*\$\s*(?:\+\s*VAT)?', block_text.replace('\x07', ' '))
            qties_found = re.findall(r'(?<!\d)([\d,]{1,10})(?!\s*\$)(?!\s*x)', block_text.replace('\x07', ' '))
            
            # Pair them up
            for qi, pi in zip(qties_found, prices_found):
                qty = parse_qty(qi)
                price = parse_price(pi + ' $')
                if qty and price and price > 1:
                    qty_prices.append({
                        'quantity': qty,
                        'quantity_raw': clean_final(qi),
                        'price': price,
                        'is_option': False,
                        'label': None,
                    })
        
        if qty_prices or specs:
            results.append({
                'customer': customer,
                'filename': fname,
                'date': mtime.strftime('%Y-%m-%d'),
                'title': title or clean_final(block_text[:80]),
                'specs': specs,
                'quantity_prices': qty_prices,
                'total_price': table_info['total_price'] if table_info else None,
            })
    
    if not results:
        # Fallback: return at least something
        results.append({
            'customer': customer,
            'filename': fname,
            'date': mtime.strftime('%Y-%m-%d'),
            'title': None,
            'specs': {},
            'quantity_prices': [],
            'total_price': None,
        })
    
    return results, None

def main():
    files = sorted(glob.glob(os.path.join(BASE, '*.doc')) + glob.glob(os.path.join(BASE, '*.docx')))
    print(f'Parsing {len(files)} files...')
    
    import win32com.client
    word = win32com.client.Dispatch('Word.Application')
    word.Visible = False
    
    all_items = []
    errors = []
    
    for idx, fpath in enumerate(files):
        fname = os.path.basename(fpath)
        if fname == 'desktop.ini':
            continue
        
        items, err = parse_file(fpath, word)
        if err:
            errors.append({'file': fname, 'error': err})
        elif items:
            all_items.extend(items)
        
        if (idx + 1) % 50 == 0:
            print(f'  Parsed {idx + 1}/{len(files)} files...')
    
    word.Quit()
    
    output = {
        'parsed_at': datetime.now().isoformat(),
        'total_items': len(all_items),
        'total_errors': len(errors),
        'items': all_items,
        'errors': errors,
    }
    
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(output, f, indent=2, ensure_ascii=False)
    
    with_price = sum(1 for r in all_items if r['quantity_prices'])
    without_price = len(all_items) - with_price
    print(f'\nDone! {len(all_items)} items, {len(errors)} errors.')
    print(f'  With price data: {with_price}')
    print(f'  Without price: {without_price}')

if __name__ == '__main__':
    main()
