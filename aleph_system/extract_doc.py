import sys
import os
import win32com.client
import pythoncom
import time

def extract_doc_text(filepath):
    pythoncom.CoInitialize()
    word = None
    try:
        word = win32com.client.Dispatch("Word.Application")
        word.Visible = False
        word.DisplayAlerts = False
        doc = word.Documents.Open(os.path.abspath(filepath), ReadOnly=True, AddToRecentFiles=False)
        text = doc.Content.Text
        doc.Close(SaveChanges=False)
        return text
    except Exception as e:
        return f"ERROR: {e}"
    finally:
        if word:
            try:
                word.Quit()
            except:
                pass
        pythoncom.CoUninitialize()

if __name__ == "__main__":
    filepath = sys.argv[1]
    if not os.path.exists(filepath):
        print(f"File not found: {filepath}")
        sys.exit(1)
    text = extract_doc_text(filepath)
    print(text)
