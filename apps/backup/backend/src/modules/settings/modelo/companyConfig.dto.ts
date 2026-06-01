import { ApiProperty } from '@nestjs/swagger';

export class CompanyConfigDto {
  @ApiProperty({ example: 'Mi Empresa' })
  companyName!: string;

  @ApiProperty({ example: '+57 300 000 0000' })
  companyPhone!: string;

  @ApiProperty({ example: 'info@miempresa.com' })
  companyEmail!: string;

  @ApiProperty({ example: 'https://miempresa.com' })
  companyWebsite!: string;

  @ApiProperty({ example: 'Calle 123 #45-67' })
  companyAddress!: string;

  @ApiProperty({ example: '/uploads/logo.png' })
  logoUrl!: string;
}

