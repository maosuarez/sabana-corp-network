Cosas a mejorar para facilidad de los retos:
1. Crear en el header despeus de estar logueado un avatar que redirija a /profile?id=id-de-jperez, de esa manera es visible la posible vulnerabilidad
2. En el error que muestra esto "Acceso denegado a /api/internal/admin/database: se requiere rol it o admin." deberiamos decir alguna pista como este link es importante porfavor no lo compartas.
3. En la vulnerabilidad de LFI, para el nivel que estamos es casi imposible que sin saber alguien llegue hasta ../../flag_lfi.txt completamente solo. asi que diria que es lecesario incluir una vulnerabilidad que sea algo asi como poder ver los archivos del directorio actual. a modo de ls, la idea es que si estan en file=./ puedan listar los archivos. de la actual y si es ../ de la anterior ../../ de la anterior y asi sucesivamente. o incluso sin el / solo el file=. y dejar una psita de eso por ahi. debe poder verse [
    - carpetas
    - si tiene carpeta anterior con ..
    - archivos
]
4. Este no sirvio /attachment?file=../../etc/passwd  tenemos que hacerlo real y crear un archivo que responda a esa ruta.
5. En el readme en la seccion del reto 1.5 que habla del JWT inseguro quiero qeu seas mas preciso de que herramientas peudo usar. donde consigo el token de sesion. como lo uso. y todo mas desmenuzado
6. para la seccion de 1.6 Stored XSS es necesario montar un servidor del lado del atacante. pero quiero saber como podria llegar a funcionar esto. que debe hacer el atacante para lograrlo. y si puede ser localhost o accesible desde el server. como se puede hacer. dame tambien el paso a paso en el readme ese esta mas claro pero se peude decir mejor. 
7. De los 5 hashes de la base de datos... solo pudimos recuperar 1 use hashcat -m 0 .env.hashes.txt ~/wordlists/rockyou.txt 2ac9cb7dc02b3c0083eb70898e549b63:Password1
lo ideal es que todos esten en un rockyou basico como el que acabamos de incluir en ~/wordlists/rockyou.txt
+----+------------+----------+----------------------------------+
| id | username   | role     | password_md5                     |
+----+------------+----------+----------------------------------+
|  1 | admin      | admin    | 9927f1db17ae310bfc693f3c3f44a0d8 |
|  2 | jperez     | employee | 281d589cf2293bdd1903021c6b601ca7 |
|  3 | it_soporte | it       | e65cdcf1faae42ac765a47d15d835422 |
|  4 | msilva     | employee | f4a7c04129e3038379ada5dd34949a9c |
|  5 | rgomez     | employee | 2ac9cb7dc02b3c0083eb70898e549b63 |
|  6 | lcastro    | employee | 19cc3eaa58b7b1cbc1f37199334e8748 |
+----+------------+----------+----------------------------------+
8. entiendo que esto es algo que se puede saber si crackeo las constrasenas... entonces debemos decirlo de una manera mas clara. vamos a agregar un ticket en el inicio que diga "Actualizacion de password del servidor linux. y que explique que el usuario msilva tiene la misma constrasena en la base de datos que para el servidor linux y que eso e speligroso. de esa manera logramos dar esa pista de una forma sutil
9. no pude entrar a el reto 3 porque no sabia la contrasena. asi que esta tambien ponla en texto plano en el readme. 