Passaggi per installazione della Dashboard :
# 1. Clona la repository dalla directory /var/www/html (o dalla cartella desiderata)
cd /var/www/html
sudo git clone https://github.com/iz1mlf595/nxdn-dashboard-dark.git
# 2. Entra nella cartella appena creata
cd /var/www/html
# 4. Assegna i permessi corretti ad Apache
sudo chown -R www-data:www-data /var/www/html/
sudo find /var/www/html/ -type d -exec chmod 755 {} \;
sudo find /var/www/html/ -type f -exec chmod 644 {} \;
