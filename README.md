# Stockpicker auto-scan

A free, automatic stock scanner that runs on GitHub, with no AI and no token cost.

Three times each weekday it:

- prices about 1,500 shares: FTSE 100/250, S&P 500, Nasdaq-100, S&P SmallCap 600, your watchlist, and every UK/AIM company that has issued an RNS in the last 4 months;
- flags **breakouts** (new 52-week high on 1.5× volume), shares **testing highs**, **oversold bounce** candidates and **news-driven movers**;
- reads new RNS headlines and tags phrases like "ahead of expectations", "placing" and "possible offer";
- reads the Takeover Panel disclosure table and logs companies that join or leave an offer period;
- publishes everything to a web page you can open on your phone.

## One-off setup (about 10 minutes)

1. **Create a free GitHub account** at https://github.com/signup if you don't have one.
2. **Create a repository.** Click **+** (top right), then **New repository**.
   - Name: `stockpicker`
   - Choose **Public**. Free GitHub Pages and unlimited free Actions minutes need a public repo. The page tells search engines not to index it. Treat the link as semi-private and don't post it publicly.
   - Click **Create repository**.
3. **Upload the files.** On the new repo page, click **uploading an existing file**. Unzip `stockpicker-auto.zip` on your computer and drag in **everything inside the folder**: `index.html`, `scan.py`, `requirements.txt`, `watchlist.csv`, `README.md` and the `data` folder. Then click **Commit changes**.
   - The `.github` folder is hidden on Mac and Windows and may not upload by dragging. If it doesn't appear in the repo, click **Add file → Create new file**, type the name `.github/workflows/scan.yml` (the slashes create the folders), paste in the contents of `scan.yml` from the zip, and click **Commit**.
4. **Allow the scanner to save its results.** Go to **Settings → Actions → General → Workflow permissions**, choose **Read and write permissions**, and click **Save**.
5. **Turn on the web page.** Go to **Settings → Pages**. Under *Build and deployment*, set Source to **Deploy from a branch**, Branch to **main** and folder to **/ (root)**, then click **Save**.
6. **Run the first scan.** Go to the **Actions** tab. If asked, click to enable workflows. Then choose **Stockpicker auto-scan → Run workflow**. A run takes about 5–10 minutes.
7. **Open your page** at `https://YOUR-GITHUB-USERNAME.github.io/stockpicker/`. Add it to your phone's home screen.

After this it runs on its own at about 07:35, 16:50 and 21:30 UK time on weekdays (one hour later in summer time).

## Changing the watchlist

Edit `watchlist.csv` on GitHub (click the file, then the pencil icon). There is one row per share:

```
ticker,yahoo,market,name
CWR,CWR.L,FTSE,Ceres Power
GATC,GATC.L,AIM,Gattaca
ETON,ETON,US,Eton Pharmaceuticals
```

London shares need `.L` on the end of the Yahoo code.

## Notes and limits

- Prices come from Yahoo Finance through the free `yfinance` library. They are delayed, and the last bar is the latest daily bar. The library is unofficial and sometimes breaks for a few days until it is updated. The page shows when the last good scan ran.
- RNS headlines are read from Investegate's public pages, and only the headline and link are stored. Investegate's terms cover private investors; keep the page for your own use.
- US names have price signals only, with no news feed. Check the news yourself, or let the daily Claude review cover it.
- `data/digest.json` is a small shortlist that the daily Claude review reads instead of searching the whole market. That is what keeps token use low.
- GitHub pauses scheduled workflows in repos with no activity for 60 days. The scanner commits its results every run, so this shouldn't happen. If it does, re-enable the workflow on the Actions tab.

Screening tool, not financial advice.
