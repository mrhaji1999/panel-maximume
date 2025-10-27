from playwright.sync_api import sync_playwright

def run(playwright):
    browser = playwright.chromium.launch()
    page = browser.new_page()
    page.goto("http://localhost:5173/login")
    page.fill('input[name="username"]', "supervisor")
    page.fill('input[name="password"]', "password")
    page.click('button[type="submit"]')
    page.goto("http://localhost:5173/assign-customers")
    page.screenshot(path="jules-scratch/verification/assign-customers.png")
    browser.close()

with sync_playwright() as playwright:
    run(playwright)
