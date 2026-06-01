import { ApiProperty } from '@nestjs/swagger';

export class ClientsStatsDto {
  @ApiProperty({ example: 100 })
  totalClients!: number;

  @ApiProperty({ example: 80 })
  individualClients!: number;

  @ApiProperty({ example: 20 })
  companyClients!: number;

  @ApiProperty({ example: 10 })
  recentClients!: number;
}

